import { config } from './config.js';
import { createPool, migrate } from './db.js';
import { Inventory } from './inventory.js';
import { createServer, listen } from './server.js';

const pool = createPool({ connectionString: config.databaseUrl });

await migrate(pool);

const server = createServer({
  inventory: new Inventory(pool),
  artificialDelayMs: config.artificialDelayMs,
});

const port = await listen(server, `${config.host}:${config.port}`);

console.log(
  JSON.stringify({
    time: new Date().toISOString(),
    message: `Inventory service listening on ${config.host}:${port}`,
    artificialDelayMs: config.artificialDelayMs,
  }),
);

// Finish in-flight calls before exiting (docker compose stop sends SIGTERM).
for (const signal of ['SIGTERM', 'SIGINT']) {
  process.once(signal, () => {
    server.tryShutdown(async () => {
      await pool.end();
      process.exit(0);
    });
  });
}
