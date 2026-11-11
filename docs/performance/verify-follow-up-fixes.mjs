// Destructive checks for the six follow-up findings: disposable novacart-perf ONLY.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {randomUUID} from 'node:crypto';
assert.equal(process.env.DISPOSABLE_CONFIRM, 'novacart-perf');
assert.ok(process.env.ADMIN_PASSWORD);
const base = 'http://127.0.0.1:18090/api/v1', nonce = randomUUID().replaceAll('-', '');
const docker = (...args) => execFileSync('docker', args, {encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']}).trim();
const sql = statement => docker('exec', 'novacart-perf_db_1', 'psql', '-U', 'novacart', '-d', 'novacart_perf', '-v', 'ON_ERROR_STOP=1', '-Atc', statement);
async function api(method, path, token, body, expected = 200, extra = {}) {
  const response = await fetch(base + path, {method, headers: {Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {}), ...extra}, body: body === undefined ? undefined : JSON.stringify(body), signal: AbortSignal.timeout(15000)});
  const raw = await response.text(), json = raw ? JSON.parse(raw) : null;
  if (expected !== null) assert.equal(response.status, expected, `${method} ${path}: ${response.status} ${raw.slice(0, 800)}`);
  return {response, json, raw};
}
const admin = (await api('POST', '/login', null, {email: 'admin@example.com', password: process.env.ADMIN_PASSWORD})).json.token;
async function register(suffix) {
  return (await api('POST', '/register', null, {username: `six${nonce}${suffix}`, email: `six${nonce}${suffix}@example.test`, password: 'RegressionOnly123!', password_confirmation: 'RegressionOnly123!'}, 201)).json;
}
const name = 'ß'.repeat(70) + nonce.slice(0, 8);
const unicode1 = (await api('POST', '/admin/categories', admin, {name}, 201)).json.data;
const unicode2 = (await api('POST', '/admin/categories', admin, {name: name + '!'}, 201)).json.data;
assert.ok(unicode1.slug.length <= 120 && unicode2.slug.length <= 120);
assert.notEqual(unicode1.slug, unicode2.slug);
console.log('PASS: expanding Unicode slugs fit PostgreSQL storage and preserve collision suffixes.');
for (const [index, password] of ['ReviewOnly\0Password123!', 'x'.repeat(73)].entries()) {
  const invalid = await api('POST', '/register', null, {username: `invalid${nonce}${index}`, email: `invalid${nonce}${index}@example.test`, password, password_confirmation: password}, 422);
  assert.ok(invalid.json.errors.password);
}
console.log('PASS: unsupported passwords return 422 field errors rather than hashing failures.');
const category = (await api('POST', '/admin/categories', admin, {name: `Six ${nonce}`}, 201)).json.data;
async function product(label, stock = 5) {
  return (await api('POST', '/admin/products', admin, {title: `Six ${nonce} ${label}`, description: 'Disposable regression fixture', price: '3.00', stock_quantity: stock, category_id: category.id}, 201)).json.data;
}
const buyer = await register('a'), stockProduct = await product('stock', 10);
await api('POST', `/cart/${stockProduct.id}`, buyer.token, {quantity: 2});
await api('POST', '/orders', buyer.token, undefined, 201);
await api('PUT', `/admin/products/${stockProduct.id}`, admin, {title: stockProduct.title + ' edited', description: stockProduct.description, price: stockProduct.price, category_id: category.id});
assert.equal((await api('GET', `/products/${stockProduct.id}`)).json.data.stock_quantity, 8);
console.log('PASS: metadata-only edits preserve stock after a purchase.');
const origin = 'https://review.example.test';
const preflight = await fetch(base + `/products/${stockProduct.id}`, {method: 'OPTIONS', headers: {Origin: origin, 'Access-Control-Request-Method': 'GET', 'Access-Control-Request-Headers': 'if-none-match'}});
assert.equal(preflight.status, 204);
assert.ok(preflight.headers.get('access-control-allow-headers').toLowerCase().includes('if-none-match'));
const full = await api('GET', `/products/${stockProduct.id}`, null, undefined, 200, {Origin: origin});
assert.ok(full.response.headers.get('access-control-expose-headers').toLowerCase().includes('etag'));
assert.equal((await api('GET', `/products/${stockProduct.id}`, null, undefined, 304, {Origin: origin, 'If-None-Match': full.response.headers.get('etag')})).raw, '');
console.log('PASS: cross-origin conditional requests are allowed, expose ETag, and return empty 304.');
async function historicalProduct(label) {
  const p = await product(label);
  await api('POST', `/cart/${p.id}`, buyer.token, {quantity: 1});
  const order = (await api('POST', '/orders', buyer.token, undefined, 201)).json.data;
  await api('PATCH', `/admin/orders/${order.id}/status`, admin, {status: 'completed'});
  return p;
}
async function waitForBarrier() {
  for (let i = 0; i < 60; i++) {
    if (Number(sql("SELECT count(*) FROM pg_stat_activity WHERE datname='novacart_perf' AND wait_event='PgSleep'")) > 0) return;
    await new Promise(resolve => setTimeout(resolve, 30));
  }
  throw new Error('SQL concurrency barrier was not reached');
}
// Checkout holds Products-owned locks; deletion must wait then reject pending history.
const first = await historicalProduct('checkout-first'), firstBuyer = await register('b');
await api('POST', `/cart/${first.id}`, firstBuyer.token, {quantity: 1});
try {
  sql(`CREATE FUNCTION verify_six_checkout() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.user_id=${Number(firstBuyer.data.id)} THEN PERFORM pg_sleep(1.5); END IF; RETURN NEW; END; $$; CREATE TRIGGER verify_six_checkout BEFORE INSERT ON orders FOR EACH ROW EXECUTE FUNCTION verify_six_checkout()`);
  const checkout = api('POST', '/orders', firstBuyer.token, undefined, null);
  await waitForBarrier();
  const [placed, deletion] = await Promise.all([checkout, api('DELETE', `/admin/products/${first.id}`, admin, undefined, null)]);
  assert.equal(placed.response.status, 201);
  assert.equal(deletion.response.status, 409);
  assert.equal(sql(`SELECT deleted_at IS NULL FROM products WHERE id=${Number(first.id)}`), 't');
} finally { sql('DROP TRIGGER IF EXISTS verify_six_checkout ON orders; DROP FUNCTION IF EXISTS verify_six_checkout()'); }
// Deletion wins its lock first; checkout must reject rather than order a removed row.
const second = await historicalProduct('delete-first'), secondBuyer = await register('c');
await api('POST', `/cart/${second.id}`, secondBuyer.token, {quantity: 1});
try {
  sql(`CREATE FUNCTION verify_six_delete() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id=${Number(second.id)} AND OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN PERFORM pg_sleep(1.5); END IF; RETURN NEW; END; $$; CREATE TRIGGER verify_six_delete BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION verify_six_delete()`);
  const deletion = api('DELETE', `/admin/products/${second.id}`, admin, undefined, null);
  await waitForBarrier();
  const [removed, checkout] = await Promise.all([deletion, api('POST', '/orders', secondBuyer.token, undefined, null)]);
  assert.equal(removed.response.status, 200);
  assert.equal(checkout.response.status, 422);
  assert.equal(sql(`SELECT count(*) FROM orders WHERE user_id=${Number(secondBuyer.data.id)}`), '0');
} finally { sql('DROP TRIGGER IF EXISTS verify_six_delete ON products; DROP FUNCTION IF EXISTS verify_six_delete()'); }
console.log('PASS: checkout-first blocks deletion; deletion-first blocks checkout; neither creates an incompatible pending order.');
// Mutate managed defaults, execute their exact startup seed path and actually restart FPM.
const electronicsId = Number(sql("SELECT id FROM categories WHERE slug='electronics'"));
const oldName = sql(`SELECT name FROM categories WHERE id=${electronicsId}`);
const seededId = Number(sql("SELECT id FROM products WHERE title='Aurora 4K Monitor 27\"' AND deleted_at IS NULL"));
const saved = (await api('GET', `/admin/products/${seededId}/edit`, admin)).json.data.product;
const alteredPrice = (Number(saved.price) + 1).toFixed(2);
let seedCheckComplete = false;
try {
  await api('PUT', `/admin/categories/${electronicsId}`, admin, {name: 'Electronics!'});
  await api('PUT', `/admin/products/${seededId}`, admin, {title: saved.title, description: saved.description, price: alteredPrice, category_id: saved.category.id});
  for (const seeder of ['CategorySeeder', 'ProductSeeder']) docker('exec', '--user', 'www-data', 'novacart-perf_app_1', 'php', 'artisan', 'db:seed', '--class=Database\\Seeders\\' + seeder, '--force');
  docker('restart', 'novacart-perf_app_1');
  let ready = false;
  for (let i = 0; i < 100; i++) {
    try { const response = await fetch(base + `/products/${seededId}`, {signal: AbortSignal.timeout(1000)}); if (response.ok) { await response.arrayBuffer(); ready = true; break; } await response.arrayBuffer(); } catch {}
    await new Promise(resolve => setTimeout(resolve, 200));
  }
  assert.ok(ready, 'App must restart successfully after supported catalog edits');
  assert.equal(sql(`SELECT name FROM categories WHERE id=${electronicsId}`), 'Electronics!');
  assert.equal(sql(`SELECT price FROM products WHERE id=${seededId}`), alteredPrice);
  assert.equal((await api('GET', `/products/${seededId}`)).json.data.stock_quantity, saved.stock_quantity);
  seedCheckComplete = true;
  console.log('PASS: seeders and a real app restart preserve renamed categories, changed prices and live stock.');
} finally {
  const restored = await Promise.allSettled([
    api('PUT', `/admin/categories/${electronicsId}`, admin, {name: oldName}),
    api('PUT', `/admin/products/${seededId}`, admin, {title: saved.title, description: saved.description, price: saved.price, category_id: saved.category.id}),
  ]);
  if (restored.some(result => result.status === 'rejected')) {
    console.error('Disposable seed fixture restoration failed; see the original test error and restore fixture values before retrying.');
    if (seedCheckComplete) throw new Error('Seed fixture restoration failed');
  }
}
console.log('PASS: all six follow-up fixes verified with real services.');
