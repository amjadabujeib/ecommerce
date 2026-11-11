// Use disposable test data: this creates users, products, orders and notifications.
// env BASE_URL=http://localhost:8080/api/v1 ADMIN_PASSWORD='...' node docs/demo/verify-requirements.mjs
// No packages required. Discard the test database after verification.

import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const base = (process.env.BASE_URL ?? 'http://localhost:8080/api/v1').replace(/\/$/, '');
assert.ok(process.env.ADMIN_PASSWORD, 'Set ADMIN_PASSWORD for the disposable test stack.');

async function call(method, path, token, body, status = 200) {
  const response = await fetch(base + path, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
    signal: AbortSignal.timeout(10_000),
  });
  const json = await response.json();
  assert.equal(response.status, status, `${method} ${path}: expected ${status}, got ${response.status}`);
  for (const header of ['x-content-type-options', 'x-frame-options', 'content-security-policy', 'referrer-policy']) {
    assert.ok(response.headers.get(header), `Missing ${header} on ${response.status}`);
  }
  if (status >= 400) {
    assert.equal(json.status, status);
    assert.equal(typeof json.code, 'string');
    assert.equal(typeof json.message, 'string');
    assert.equal(json.trace, undefined);
  }
  return json;
}

const run = randomUUID().replaceAll('-', '');
const admin = (await call('POST', '/login', null, {
  email: process.env.ADMIN_EMAIL ?? 'admin@example.com', password: process.env.ADMIN_PASSWORD,
})).token;
const register = (i) => call('POST', '/register', null, {
  username: `verify${run}${i}`, email: `verify${run}${i}@example.com`,
  password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!', role: 'admin',
}, 201);
const alice = await register(1);
const bob = await register(2);
assert.equal(alice.data.role, 'customer');
await call('GET', '/orders', null, undefined, 401);
await call('GET', '/orders', 'invalid', undefined, 401);
await call('POST', '/admin/categories', alice.token, { name: 'Forbidden' }, 403);

const category = (await call('POST', '/admin/categories', admin, { name: `Verify ${run}` }, 201)).data;
const form = new FormData();
for (const [key, value] of Object.entries({
  title: `Verify ${run}`, description: 'Disposable acceptance fixture',
  price: '12.50', category_id: category.id, stock_quantity: 5,
})) {
  form.set(key, String(value));
}
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
form.set('image', new Blob([png], { type: 'image/png' }), 'fixture.png');
const product = (await call('POST', '/admin/products', admin, form, 201)).data;
assert.ok(product.image_path);
form.set('image', new Blob(['not an image'], { type: 'image/png' }), 'fake.png');
await call('POST', '/admin/products', admin, form, 422);
const listed = await call('GET', `/?category=${category.id}`);
assert.equal(listed.data.length, 1);
assert.equal(listed.data[0].id, product.id);
assert.ok(listed.meta);
assert.ok(listed.links);

await call('POST', `/cart/${product.id}`, alice.token, { quantity: 2 });
const order = (await call('POST', '/orders', alice.token, { total_amount: '0.01' }, 201)).data;
assert.equal(order.total_amount, '25.00');
assert.equal((await call('GET', `/products/${product.id}`)).data.stock_quantity, 3);
await call('GET', `/orders/${order.id}`, bob.token, undefined, 403);
await call('PATCH', `/admin/orders/${order.id}/status`, alice.token, { status: 'cancelled' }, 403);
assert.ok((await call('GET', '/notifications', alice.token)).data.length > 0);
await call('PATCH', `/admin/orders/${order.id}/status`, admin, { status: 'completed' });
await call('PATCH', `/admin/orders/${order.id}/status`, admin, { status: 'cancelled' });
await call('PATCH', `/admin/orders/${order.id}/status`, admin, { status: 'cancelled' });
assert.equal((await call('GET', `/products/${product.id}`)).data.stock_quantity, 5);
await call('PATCH', `/admin/orders/${order.id}/status`, admin, { status: 'completed' }, 422);

await call('POST', `/cart/${product.id}`, bob.token, { quantity: 2 });
await call('PATCH', `/admin/products/${product.id}/stock`, admin, { stock_quantity: 0 });
await call('POST', '/orders', bob.token, undefined, 409);
assert.equal((await call('GET', '/orders', bob.token)).data.length, 0);
const refreshed = await call('POST', '/refresh', bob.token);
await call('GET', '/me', bob.token, undefined, 401);
await call('POST', '/logout', refreshed.token);
await call('GET', '/me', refreshed.token, undefined, 401);

for (let i = 0; i < 6; i++) {
  await call('POST', '/login', null, { email: `throttle${run}@example.com`, password: 'wrong-password' }, 422);
}
await call('POST', '/login', null, { email: `throttle${run}@example.com`, password: 'wrong-password' }, 429);
console.log('PASS: registration/role escalation, JWT/refresh/logout, IDOR, RBAC, image validation, filtering/pagination, authoritative totals, notifications, cancellation, shortages, login throttling, error envelopes and security headers.');
