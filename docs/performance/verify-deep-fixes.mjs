// Destructive regression checks: ONLY the authorized disposable novacart-perf stack.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import {performance} from 'node:perf_hooks';
assert.equal(process.env.DISPOSABLE_CONFIRM, 'novacart-perf');
assert.ok(process.env.ADMIN_PASSWORD);
const base = 'http://127.0.0.1:18090/api/v1';
const docker = (...args) => execFileSync('docker', args, {encoding: 'utf8'}).trim();
const sql = (statement, inventory = false) => docker('exec', inventory ? 'novacart-perf_inventory-db_1' : 'novacart-perf_db_1', 'psql', '-U', inventory ? 'inventory' : 'novacart', '-d', inventory ? 'inventory' : 'novacart_perf', '-v', 'ON_ERROR_STOP=1', '-Atc', statement);
async function request(method, path, token, body, expected = 200) {
  const response = await fetch(base + path, {method, headers: {Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {})}, body: body === undefined ? undefined : JSON.stringify(body), signal: AbortSignal.timeout(15000)});
  const json = await response.json();
  if (expected !== null) assert.equal(response.status, expected, `${method} ${path}: ${response.status} ${JSON.stringify(json)}`);
  return {response, json};
}
const nonce = randomUUID().replaceAll('-', '');
const admin = (await request('POST', '/login', null, {email: 'admin@example.com', password: process.env.ADMIN_PASSWORD})).json.token;
async function register(suffix) {
  return (await request('POST', '/register', null, {username: `fix${nonce}${suffix}`, email: `fix${nonce}${suffix}@example.test`, password: 'RegressionOnly123!', password_confirmation: 'RegressionOnly123!'}, 201)).json;
}
const category = (await request('POST', '/admin/categories', admin, {name: `Fixes ${nonce}`}, 201)).json.data;
async function product(suffix, price = '1.00', stock = 10) {
  return (await request('POST', '/admin/products', admin, {title: `Fixes ${nonce} ${suffix}`, description: 'Disposable regression fixture', price, stock_quantity: stock, category_id: category.id}, 201)).json.data;
}
// Cancellation has a durable intent even if local persistence fails AFTER Release.
const alice = await register('a'), bob = await register('b');
const single = await product('single', '1.00', 1);
await request('POST', `/cart/${single.id}`, alice.token, {quantity: 1});
const order = (await request('POST', '/orders', alice.token, undefined, 201)).json.data;
try {
  sql(`CREATE FUNCTION verify_fix_cancel() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id=${Number(order.id)} AND NEW.status='cancelled' THEN RAISE EXCEPTION 'Disposable cancellation persistence fault'; END IF; RETURN NEW; END; $$; CREATE TRIGGER verify_fix_cancel BEFORE UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION verify_fix_cancel()`);
  await request('PATCH', `/admin/orders/${order.id}/status`, admin, {status: 'cancelled'}, 500);
  assert.equal(sql(`SELECT state FROM order_cancellations WHERE order_id=${Number(order.id)}`), 'pending');
  assert.equal((await request('GET', `/products/${single.id}`)).json.data.stock_quantity, 1);
  await request('PATCH', `/admin/orders/${order.id}/status`, admin, {status: 'completed'}, 409);
} finally {
  sql('DROP TRIGGER IF EXISTS verify_fix_cancel ON orders; DROP FUNCTION IF EXISTS verify_fix_cancel()');
}
await request('POST', `/cart/${single.id}`, bob.token, {quantity: 1});
await request('POST', '/orders', bob.token, undefined, 201);
docker('exec', '--user', 'www-data', 'novacart-perf_app_1', 'php', 'artisan', 'orders:recover-cancellations');
assert.equal(sql(`SELECT status FROM orders WHERE id=${Number(order.id)}`), 'cancelled');
assert.equal(sql(`SELECT state FROM order_cancellations WHERE order_id=${Number(order.id)}`), 'completed');
assert.equal((await request('GET', `/products/${single.id}`)).json.data.stock_quantity, 0);
docker('exec', '--user', 'www-data', 'novacart-perf_app_1', 'php', 'artisan', 'orders:recover-cancellations');
assert.equal((await request('GET', `/products/${single.id}`)).json.data.stock_quantity, 0);
console.log('PASS: post-Release database failure blocks completion; recovery cancels exactly once after returned stock is resold.');
// Concurrent additions use a shared cart lock, including its first creation.
const cartUser = await register('c');
const products = [];
for (let index = 0; index < 10; index++) products.push(await product(`cart-${index}`));
await Promise.all(products.map(p => request('POST', `/cart/${p.id}`, cartUser.token, {quantity: 1})));
const cart = (await request('GET', '/cart', cartUser.token)).json.data;
assert.equal(cart.items.length, 10);
assert.deepEqual(cart.items.map(i => i.product.id).sort((a, b) => a - b), products.map(p => p.id).sort((a, b) => a - b));
// One cart revision can be consumed only once, while enough stock exists for both attempts.
const orders = await Promise.all([request('POST', '/orders', cartUser.token, undefined, null), request('POST', '/orders', cartUser.token, undefined, null)]);
assert.deepEqual(orders.map(o => o.response.status).sort(), [201, 422]);
assert.equal(sql(`SELECT count(*) FROM orders WHERE user_id=${Number(cartUser.data.id)}`), '1');
console.log('PASS: ten concurrent additions retain ten lines; two concurrent checkouts create exactly one order.');
// A cart addition while checkout is paused must remain unpurchased in the cart.
const editing = await register('d');
await request('POST', `/cart/${products[0].id}`, editing.token, {quantity: 1});
try {
  sql(`CREATE FUNCTION verify_fix_cart_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.user_id=${Number(editing.data.id)} THEN PERFORM pg_sleep(1); END IF; RETURN NEW; END; $$; CREATE TRIGGER verify_fix_cart_delay BEFORE INSERT ON orders FOR EACH ROW EXECUTE FUNCTION verify_fix_cart_delay()`);
  const checkout = request('POST', '/orders', editing.token, undefined, 201);
  let inFlight = false;
  for (let attempt = 0; attempt < 60; attempt++) {
    if (Number(sql("SELECT count(*) FROM pg_stat_activity WHERE datname='novacart_perf' AND wait_event='PgSleep'")) > 0) { inFlight = true; break; }
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  assert.ok(inFlight, 'Checkout must be paused before the concurrent addition');
  const addition = request('POST', `/cart/${products[1].id}`, editing.token, {quantity: 1});
  const [placed] = await Promise.all([checkout, addition]);
  assert.equal(placed.json.data.items.length, 1);
  const remaining = (await request('GET', '/cart', editing.token)).json.data.items;
  assert.equal(remaining.length, 1);
  assert.equal(remaining[0].product.id, products[1].id);
} finally {
  sql('DROP TRIGGER IF EXISTS verify_fix_cart_delay ON orders; DROP FUNCTION IF EXISTS verify_fix_cart_delay()');
}
console.log('PASS: an item added during checkout remains in the cart and is not silently consumed.');
// PostgreSQL numeric bounds are validated before even creating a remote reservation.
const high = await product('large', '60000000.00', 2);
await request('POST', `/cart/${high.id}`, alice.token, {quantity: 2});
const reservationsBefore = sql('SELECT count(*) FROM reservations', true);
const oversized = await request('POST', '/orders', alice.token, undefined, 422);
assert.ok(oversized.json.errors.total_amount);
assert.equal(sql('SELECT count(*) FROM reservations', true), reservationsBefore);
assert.equal((await request('GET', '/cart', alice.token)).json.data.item_count, 2);
assert.equal((await request('GET', `/products/${high.id}`)).json.data.stock_quantity, 2);
console.log('PASS: oversized aggregate returns 422 without reserving stock or dropping the cart.');
// Preexisting oversized carts are repairable; the 100-line RPC boundary succeeds.
const largeCartUser = await register('e');
assert.equal(sql('SELECT count(*) FROM products WHERE id BETWEEN 10001 AND 10101 AND deleted_at IS NULL'), '101', 'Seed the standard performance dataset first');
sql(`INSERT INTO carts (user_id,created_at,updated_at) VALUES (${Number(largeCartUser.data.id)},now(),now()); INSERT INTO cart_items (cart_id,product_id,quantity,created_at,updated_at) SELECT (SELECT id FROM carts WHERE user_id=${Number(largeCartUser.data.id)}),id,1,now(),now() FROM products WHERE id BETWEEN 10001 AND 10101`);
const beforeLarge = sql('SELECT count(*) FROM reservations', true);
const excess = await request('POST', '/orders', largeCartUser.token, undefined, 422);
assert.ok(excess.json.errors.cart);
assert.equal(sql('SELECT count(*) FROM reservations', true), beforeLarge);
await request('DELETE', '/cart/10101', largeCartUser.token);
const overflow = await request('POST', `/cart/${products[0].id}`, largeCartUser.token, {quantity: 1}, 422);
assert.ok(overflow.json.errors.cart);
const boundary = (await request('POST', '/orders', largeCartUser.token, undefined, 201)).json.data;
assert.equal(boundary.items.length, 100);
console.log('PASS: 101-line legacy cart returns 422 without RPC, rejects a new line at the cap, and orders successfully after repair to 100 lines.');
// A connected Redis that stops answering must fail open within a bounded interval.
try {
  docker('kill', '--signal', 'STOP', 'novacart-perf_redis_1');
  const start = performance.now();
  const response = await fetch(base + `/products/${products[0].id}`, {headers: {Accept: 'application/json'}, signal: AbortSignal.timeout(3000)});
  await response.arrayBuffer();
  const elapsed = performance.now() - start;
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('x-catalog-cache'), 'unavailable');
  assert.ok(elapsed < 2500, `Cache stall took ${elapsed.toFixed(1)}ms`);
  console.log(`PASS: stalled Redis bypassed in ${elapsed.toFixed(1)}ms with HTTP 200.`);
} finally {
  docker('kill', '--signal', 'CONT', 'novacart-perf_redis_1');
}
console.log('PASS: all five deep-review fixes verified against disposable HTTP/gRPC/PostgreSQL/Redis.');
