import grpc from '@grpc/grpc-js';
import protoLoader from '@grpc/proto-loader';
import { setTimeout as sleep } from 'node:timers/promises';
import { protoPath } from './config.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const MAX_ITEMS = 100;
const MAX_QUANTITY = 1_000_000;

/**
 * Load the shared contract. keepCase keeps the snake_case field names of the
 * .proto file, so both languages use the same names.
 */
export function loadContract(path = protoPath) {
  const definition = protoLoader.loadSync(path, {
    keepCase: true,
    longs: Number,
    enums: String,
    defaults: true,
    oneofs: true,
  });

  return grpc.loadPackageDefinition(definition).inventory.v1;
}

/**
 * Build the gRPC server around an Inventory.
 *
 * @param {object} options
 * @param {import('./inventory.js').Inventory} options.inventory
 * @param {number} [options.artificialDelayMs]
 * @param {(entry: object) => void} [options.log]
 */
export function createServer({ inventory, artificialDelayMs = 0, log = defaultLog }) {
  const contract = loadContract();
  const server = new grpc.Server();

  /**
   * Wrap a handler with validation errors, logging and the optional delay.
   *
   * @param {string} method
   * @param {(request: any) => Promise<any>} handler
   */
  const unary = (method, handler) => async (call, callback) => {
    const started = Date.now();

    try {
      if (artificialDelayMs > 0) {
        await sleep(artificialDelayMs);
      }

      const response = await handler(call.request);
      log({ method, ms: Date.now() - started, outcome: response.status ?? 'OK' });
      callback(null, response);
    } catch (error) {
      const status = error instanceof InvalidArgument ? grpc.status.INVALID_ARGUMENT : grpc.status.INTERNAL;
      log({ method, ms: Date.now() - started, outcome: grpc.status[status], error: error.message });
      callback({
        code: status,
        // Internal details stay in the log; the caller gets a generic message.
        details: status === grpc.status.INTERNAL ? 'Internal inventory error' : error.message,
      });
    }
  };

  server.addService(contract.InventoryService.service, {
    GetStock: unary('GetStock', async (request) => {
      const ids = request.product_ids.map((id) => productId(id));

      return { levels: await inventory.getStock(ids) };
    }),

    Reserve: unary('Reserve', async (request) => {
      const reservationId = reservationIdOf(request);

      if (request.items.length === 0 || request.items.length > MAX_ITEMS) {
        throw new InvalidArgument(`A reservation needs between 1 and ${MAX_ITEMS} items`);
      }

      const items = request.items.map((item) => ({
        product_id: productId(item.product_id),
        quantity: quantityOf(item.quantity, 1),
      }));

      const result = await inventory.reserve(reservationId, items);

      return {
        reservation_id: reservationId,
        status: `RESERVATION_STATUS_${result.status}`,
        shortages: result.shortages,
      };
    }),

    Release: unary('Release', async (request) => ({
      released: await inventory.release(reservationIdOf(request)),
    })),

    SetStock: unary('SetStock', async (request) => ({
      level: await inventory.setStock(productId(request.product_id), quantityOf(request.quantity, 0)),
    })),
  });

  return server;
}

/**
 * @param {grpc.Server} server
 * @param {string} address  e.g. "0.0.0.0:50051" or "127.0.0.1:0" for a random port
 * @returns {Promise<number>} the bound port
 */
export function listen(server, address) {
  return new Promise((resolve, reject) => {
    server.bindAsync(address, grpc.ServerCredentials.createInsecure(), (error, port) =>
      error ? reject(error) : resolve(port),
    );
  });
}

class InvalidArgument extends Error {}

function productId(value) {
  if (!Number.isSafeInteger(value) || value <= 0) {
    throw new InvalidArgument(`Invalid product id: ${value}`);
  }

  return value;
}

function quantityOf(value, minimum) {
  if (!Number.isInteger(value) || value < minimum || value > MAX_QUANTITY) {
    throw new InvalidArgument(`Quantity must be between ${minimum} and ${MAX_QUANTITY}`);
  }

  return value;
}

function reservationIdOf(request) {
  if (!UUID.test(request.reservation_id)) {
    throw new InvalidArgument('reservation_id must be a UUID');
  }

  return request.reservation_id.toLowerCase();
}

function defaultLog(entry) {
  console.log(JSON.stringify({ time: new Date().toISOString(), ...entry }));
}
