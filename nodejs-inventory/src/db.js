import pg from 'pg';

/**
 * The inventory schema. CHECK (quantity >= 0) is the last line of defence:
 * even a bug in the service code cannot make stock negative.
 */
const SCHEMA = `
  CREATE TABLE IF NOT EXISTS stock (
    product_id  BIGINT PRIMARY KEY,
    quantity    INTEGER NOT NULL CHECK (quantity >= 0),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
  );

  CREATE TABLE IF NOT EXISTS reservations (
    id           UUID PRIMARY KEY,
    status       TEXT NOT NULL CHECK (status IN ('reserved', 'released')),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    released_at  TIMESTAMPTZ
  );

  CREATE TABLE IF NOT EXISTS reservation_items (
    reservation_id  UUID NOT NULL REFERENCES reservations (id) ON DELETE CASCADE,
    product_id      BIGINT NOT NULL,
    quantity        INTEGER NOT NULL CHECK (quantity > 0),
    PRIMARY KEY (reservation_id, product_id)
  );
`;

/**
 * @param {object} options
 * @param {string} options.connectionString
 * @param {string} [options.schema]  Postgres schema to work in (tests use a
 *   throw-away schema so they never touch real data).
 * @returns {pg.Pool}
 */
export function createPool({ connectionString, schema }) {
  return new pg.Pool({
    connectionString,
    max: 10,
    connectionTimeoutMillis: 3000,
    ...(schema ? { options: `-c search_path=${schema}` } : {}),
  });
}

/**
 * Create the tables if they do not exist yet. An advisory lock makes this safe
 * when several instances start at the same time.
 *
 * @param {pg.Pool} pool
 */
export async function migrate(pool) {
  const client = await pool.connect();

  try {
    await client.query('SELECT pg_advisory_lock(7317)');
    await client.query(SCHEMA);
  } finally {
    await client.query('SELECT pg_advisory_unlock(7317)').catch(() => {});
    client.release();
  }
}

/**
 * Run `work` inside a transaction on one connection and always give the
 * connection back.
 *
 * @template T
 * @param {pg.Pool} pool
 * @param {(client: pg.PoolClient) => Promise<T>} work
 * @returns {Promise<T>}
 */
export async function transaction(pool, work) {
  const client = await pool.connect();

  try {
    await client.query('BEGIN');
    const result = await work(client);
    await client.query('COMMIT');

    return result;
  } catch (error) {
    await client.query('ROLLBACK').catch(() => {});
    throw error;
  } finally {
    client.release();
  }
}
