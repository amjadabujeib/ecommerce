import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createPool, migrate } from '../src/db.js';
import { newId } from './helpers.js';

test('migration upgrades existing reservations and is safe to repeat', async () => {
  const connectionString = process.env.TEST_DATABASE_URL ?? process.env.DATABASE_URL;
  assert.ok(connectionString, 'Set TEST_DATABASE_URL to a disposable PostgreSQL database.');
  const schema = `test_${newId().replaceAll('-', '')}`;
  const admin = createPool({ connectionString });
  let pool;
  try {
    await admin.query(`CREATE SCHEMA ${schema}`);
    pool = createPool({ connectionString, schema });
    await pool.query(`CREATE TABLE reservations (
      id UUID PRIMARY KEY,
      status TEXT NOT NULL CHECK (status IN ('reserved', 'released')),
      created_at TIMESTAMPTZ NOT NULL DEFAULT now(), released_at TIMESTAMPTZ
    )`);
    const id = newId();
    await pool.query("INSERT INTO reservations (id, status) VALUES ($1, 'reserved')", [id]);
    await migrate(pool);
    await migrate(pool);
    const { rows } = await pool.query('SELECT id, status, shortages FROM reservations WHERE id = $1', [id]);
    assert.deepEqual(rows, [{ id, status: 'reserved', shortages: [] }]);
    await pool.query("INSERT INTO reservations (id, status) VALUES ($1, 'rejected')", [newId()]);
  } finally {
    await pool?.end();
    await admin.query(`DROP SCHEMA IF EXISTS ${schema} CASCADE`);
    await admin.end();
  }
});
