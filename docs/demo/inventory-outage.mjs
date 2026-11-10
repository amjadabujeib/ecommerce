// Run against a disposable stack only. Stops and restarts INVENTORY_CONTAINER.
// env ADMIN_PASSWORD='...' INVENTORY_CONTAINER=... node docs/demo/inventory-outage.mjs
// BASE_URL defaults to http://localhost:8080/api/v1; CONTAINER_ENGINE to docker.
// Leaves fixture accounts/history in the disposable database; creates no files with tokens.

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';

const base = (process.env.BASE_URL ?? 'http://localhost:8080/api/v1').replace(/\/$/, '');
const container = process.env.INVENTORY_CONTAINER;
assert.ok(container, 'Set INVENTORY_CONTAINER to the disposable inventory container.');
assert.ok(process.env.ADMIN_PASSWORD, 'Set ADMIN_PASSWORD for the disposable test stack.');
const engine = process.env.CONTAINER_ENGINE ?? 'docker';

async function api(method, path, token, body) {
  const response = await fetch(`${base}${path}`, {
    method,
    headers: {
      Accept: 'application/json', 'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
    signal: AbortSignal.timeout(10_000),
  });
  return { status: response.status, headers: response.headers, json: await response.json() };
}

async function ok(method, path, token, body, status = 200) {
  const result = await api(method, path, token, body);
  assert.equal(result.status, status, `${method} ${path}: expected ${status}, got ${result.status}`);
  return result.json;
}

const run = randomUUID().replaceAll('-', '');
const admin = (await ok('POST', '/login', null, {
  email: process.env.ADMIN_EMAIL ?? 'admin@example.com', password: process.env.ADMIN_PASSWORD,
})).token;
const customer = (await ok('POST', '/register', null, {
  username: `outage${run}`, email: `outage${run}@example.com`,
  password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!',
}, 201)).token;
const category = (await ok('POST', '/admin/categories', admin, { name: `Outage ${run}` }, 201)).data;
const product = (await ok('POST', '/admin/products', admin, {
  title: `Outage ${run}`, description: 'Disposable outage fixture',
  price: '1.00', stock_quantity: 3, category_id: category.id,
}, 201)).data;
await ok('POST', `/cart/${product.id}`, customer, { quantity: 1 });

try {
  execFileSync(engine, ['stop', container], { stdio: 'ignore' });
  const started = Date.now();
  const checkout = await api('POST', '/orders', customer);
  const elapsed = Date.now() - started;
  assert.equal(checkout.status, 503);
  assert.equal(checkout.json.code, 'service_unavailable');
  assert.equal(checkout.headers.get('retry-after'), '5');
  assert.ok(elapsed < 6000, `Checkout took ${elapsed} ms, including cleanup RPC.`);
  const page = await ok('GET', `/products/${product.id}`);
  assert.equal(page.data.stock_quantity, null);
  assert.equal(page.data.stock_status, 'unknown');
  assert.equal((await ok('GET', '/orders', customer)).data.length, 0);
  console.log(`PASS: stopped inventory -> 503 in ${elapsed} ms, Retry-After 5, unknown stock, no order.`);
} finally {
  // Restart even if stopping or an assertion fails; no automatic checkout retry.
  execFileSync(engine, ['start', container], { stdio: 'ignore' });
}

const recoveryStarted = Date.now();
let recovered = false;
while (Date.now() - recoveryStarted < 15_000) {
  const page = await ok('GET', `/products/${product.id}`);
  if (page.data.stock_quantity === 3) {
    recovered = true;
    break;
  }
  await sleep(100);
}
assert.ok(recovered, 'Inventory stock did not become readable within the recovery window.');
const order = (await ok('POST', '/orders', customer, undefined, 201)).data;
assert.equal((await ok('GET', `/products/${product.id}`)).data.stock_quantity, 2);
console.log(`PASS: service restart recovered in ${Date.now() - recoveryStarted} ms; original cart checked out once.`);
await ok('PATCH', `/admin/orders/${order.id}/status`, admin, { status: 'cancelled' });
await ok('PATCH', `/admin/products/${product.id}/stock`, admin, { stock_quantity: 0 });
await ok('DELETE', `/admin/products/${product.id}`, admin);
console.log('Fixture order cancelled and product removed; discard the disposable database after verification.');
