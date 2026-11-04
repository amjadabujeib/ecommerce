import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * Runtime settings, all read from the environment so the same image runs in
 * Docker, in tests and on a developer machine.
 */
export const config = {
  /** Address the gRPC server listens on. */
  host: process.env.GRPC_HOST ?? '0.0.0.0',
  port: Number(process.env.GRPC_PORT ?? 50051),

  /** The inventory service's own database. No other service connects to it. */
  databaseUrl:
    process.env.DATABASE_URL ?? 'postgres://inventory:inventory@localhost:5432/inventory',

  /**
   * Artificial delay added to every call, in milliseconds. Zero in normal
   * use; set it (for example to 5000) to demonstrate what the application
   * does when the inventory service is slow.
   */
  artificialDelayMs: Number(process.env.INVENTORY_ARTIFICIAL_DELAY_MS ?? 0),
};

/**
 * Path of the shared contract. Inside the Docker image it is copied next to
 * the service (see the Dockerfile); in the repository it lives in proto/ at
 * the project root, next to laravel-app/ and nodejs-inventory/.
 */
const candidates = [
  process.env.PROTO_PATH,
  fileURLToPath(new URL('../proto/inventory/v1/inventory.proto', import.meta.url)),
  fileURLToPath(new URL('../../proto/inventory/v1/inventory.proto', import.meta.url)),
].filter(Boolean);

export const protoPath = candidates.find((path) => existsSync(path)) ?? candidates[0];
