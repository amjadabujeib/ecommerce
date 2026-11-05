// Used by the Docker health check: succeeds when the service answers a real
// gRPC call within two seconds.
import grpc from '@grpc/grpc-js';
import { config } from './config.js';
import { loadContract } from './server.js';

const { InventoryService } = loadContract();
const client = new InventoryService(`127.0.0.1:${config.port}`, grpc.credentials.createInsecure());

client.GetStock({ product_ids: [] }, { deadline: Date.now() + 2000 }, (error) => {
  client.close();
  process.exit(error ? 1 : 0);
});
