import grpc from '@grpc/grpc-js';
import { randomUUID } from 'node:crypto';
import { createPool, migrate } from '../src/db.js';
import { Inventory } from '../src/inventory.js';
import { createServer, listen, loadContract } from '../src/server.js';

/**
 * Start a real inventory server on a random port, backed by a throw-away
 * Postgres schema, and return a gRPC client for it. Tests therefore go through
 * the same contract, network layer and database locks as production.
 *
 * Needs a Postgres database: TEST_DATABASE_URL, or DATABASE_URL (inside the
 * Docker container). The data of that database is never touched.
 */
export async function startInventory({ artificialDelayMs = 0 } = {}) {
  const connectionString = process.env.TEST_DATABASE_URL ?? process.env.DATABASE_URL;

  if (!connectionString) {
    throw new Error('Set TEST_DATABASE_URL (or DATABASE_URL) to a Postgres database to run the tests.');
  }

  const schema = `test_${randomUUID().replaceAll('-', '')}`;

  const admin = createPool({ connectionString });
  await admin.query(`CREATE SCHEMA ${schema}`);

  const pool = createPool({ connectionString, schema });
  await migrate(pool);

  const server = createServer({ inventory: new Inventory(pool), artificialDelayMs, log: () => {} });
  const port = await listen(server, '127.0.0.1:0');

  const { InventoryService } = loadContract();
  const client = new InventoryService(`127.0.0.1:${port}`, grpc.credentials.createInsecure());

  /** Promise wrapper: call('Reserve', {...}, {deadlineMs}) */
  const call = (method, request, { deadlineMs = 5000 } = {}) =>
    new Promise((resolve, reject) => {
      client[method](request, { deadline: Date.now() + deadlineMs }, (error, response) =>
        error ? reject(error) : resolve(response),
      );
    });

  const stop = async () => {
    client.close();
    await new Promise((resolve) => server.tryShutdown(resolve));
    await pool.end();
    await admin.query(`DROP SCHEMA ${schema} CASCADE`);
    await admin.end();
  };

  return { call, stop };
}

export const newId = () => randomUUID();
