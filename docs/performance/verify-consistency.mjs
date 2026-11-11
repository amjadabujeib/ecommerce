// Mutates stock, orders, and WAL replay. Run only on the disposable novacart-perf stack.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {randomUUID} from 'node:crypto';

assert.equal(process.env.DISPOSABLE_CONFIRM, 'novacart-perf');
assert.ok(process.env.ADMIN_PASSWORD, 'Set ADMIN_PASSWORD for the isolated stack');
const base = 'http://127.0.0.1:18090/api/v1';
const sql = (container, statement) => execFileSync('docker', ['exec', container, 'psql', '-U', 'novacart', '-d', 'novacart_perf', '-At', '-v', 'ON_ERROR_STOP=1', '-c', statement], {encoding: 'utf8'}).trim();
const standby = 'novacart-perf_db-replica_1', primary = 'novacart-perf_db_1';
async function request(method, path, token, body, status = 200, extra = {}) {
  const response = await fetch(base + path, {method, headers: {Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {}), ...extra}, body: body === undefined ? undefined : JSON.stringify(body), signal: AbortSignal.timeout(10000)});
  const raw = await response.text();
  assert.equal(response.status, status, `${method} ${path}: ${response.status} ${raw.slice(0, 500)}`);
  return {response, raw, json: raw ? JSON.parse(raw) : null};
}
const admin = (await request('POST', '/login', null, {email: 'admin@example.com', password: process.env.ADMIN_PASSWORD})).json.token;
const nonce = randomUUID().replaceAll('-', '');
const customer = (await request('POST', '/register', null, {username: `r3${nonce}`, email: `r3${nonce}@example.test`, password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!'}, 201)).json.token;
await request('POST', '/cart/10001', customer, {quantity: 1});
let customerReplicaReady = false;
for (let attempt = 0; attempt < 40; attempt++) {
  const ready = await request('GET', '/orders', customer);
  if (ready.response.headers.get('x-read-database') === 'replica') { customerReplicaReady = true; break; }
  await new Promise(resolve => setTimeout(resolve, 250));
}
assert.ok(customerReplicaReady, 'Customer fence must reach replica before pausing it');
const detail = '/products/10001';
const first = await request('GET', detail);
const tag = first.response.headers.get('etag');
assert.ok(tag);
const cached = await request('GET', detail, null, undefined, 304, {'If-None-Match': tag});
assert.equal(cached.raw, '');
assert.equal(cached.response.headers.get('x-catalog-cache'), 'hit');
assert.equal(sql(primary, 'SELECT state FROM pg_stat_replication'), 'streaming');
assert.equal(sql(standby, 'SELECT pg_is_in_recovery()'), 't');
assert.equal(sql(standby, 'SELECT pg_wal_replay_pause()'), '');
try {
  assert.equal(sql(standby, 'SELECT pg_is_wal_replay_paused()'), 't');
  const newCustomer = (await request('POST', '/register', null, {username: `lag${nonce}`, email: `lag${nonce}@example.test`, password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!'}, 201)).json.token;
  const newCustomerOrders = await request('GET', '/orders', newCustomer);
  assert.equal(newCustomerOrders.response.headers.get('x-read-database'), 'primary');
  assert.deepEqual(newCustomerOrders.json.data, []);
  const before = first.json.data;
  const title = `Fresh ${nonce}`;
  const price = (Number(before.price) + 1).toFixed(2);
  await request('PUT', `/admin/products/${before.id}`, admin, {title, description: before.description, price, category_id: before.category.id, stock_quantity: before.stock_quantity});
  const changed = await request('GET', detail, null, undefined, 200, {'If-None-Match': tag});
  assert.equal(changed.json.data.title, title);
  assert.notEqual(changed.response.headers.get('etag'), tag);
  assert.equal(changed.response.headers.get('x-read-database'), 'primary');
  assert.notEqual(sql(standby, `SELECT title FROM products WHERE id=${Number(before.id)}`), title);
  const freshTag = changed.response.headers.get('etag');
  assert.equal((await request('GET', detail, null, undefined, 304, {'If-None-Match': freshTag})).raw, '');
  const cart = await request('GET', '/cart', customer);
  assert.equal(cart.response.headers.get('x-read-database'), 'primary');
  assert.equal(cart.json.data.subtotal, price, 'Cart must use the current primary price');
  const checkout = await request('GET', '/checkout', customer);
  assert.equal(checkout.response.headers.get('x-read-database'), 'primary');
  assert.equal(checkout.json.data.subtotal, price, 'Checkout preview must use the current primary price');
  const order = (await request('POST', '/orders', customer, undefined, 201)).json.data;
  assert.equal(order.total_amount, price, 'Placed order must match the fresh preview');
  const list = await request('GET', '/orders', customer);
  assert.equal(list.response.headers.get('x-read-database'), 'primary');
  assert.ok(list.json.data.some(item => item.id === order.id));
  assert.equal(sql(standby, `SELECT count(*) FROM orders WHERE id=${Number(order.id)}`), '0');
  const stock = await request('GET', detail, null, undefined, 200, {'If-None-Match': freshTag});
  assert.equal(stock.json.data.stock_quantity, before.stock_quantity - 1);
  assert.notEqual(stock.response.headers.get('etag'), freshTag);
  console.log('PASS: fresh cart/checkout prices match placed order; cache invalidation and live stock update ETags; lagged product/order reads show committed writes.');
} finally {
  sql(standby, 'SELECT pg_wal_replay_resume()');
}
for (let attempt = 0; attempt < 40; attempt++) {
  const list = await request('GET', '/orders', customer);
  if (list.response.headers.get('x-read-database') === 'replica') { console.log('PASS: reads return to replica after WAL replay catches up.'); process.exit(0); }
  await new Promise(resolve => setTimeout(resolve, 250));
}
throw new Error('Replica did not catch up within 10 seconds');
