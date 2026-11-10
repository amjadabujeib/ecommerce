// Public-HTTP regression checks for module integration. Disposable stack only.
// env BASE_URL=http://localhost:8080/api/v1 ADMIN_PASSWORD='...' node docs/demo/verify-modularity.mjs
// Leaves account/order/history fixtures; discard the disposable databases afterward.

import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const base = (process.env.BASE_URL ?? 'http://localhost:8080/api/v1').replace(/\/$/, '');
assert.ok(process.env.ADMIN_PASSWORD, 'Set ADMIN_PASSWORD for the disposable test stack.');
async function api(method, path, token, body, expected = 200) {
  const response = await fetch(base + path, {
    method,
    headers: {
      Accept: 'application/json', 'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
    signal: AbortSignal.timeout(10_000),
  });
  const data = await response.json();
  assert.equal(response.status, expected, `${method} ${path}: expected ${expected}, got ${response.status}`);
  return data;
}
const run = randomUUID().replaceAll('-', '');
const admin = (await api('POST', '/login', null, {
  email: process.env.ADMIN_EMAIL ?? 'admin@example.com', password: process.env.ADMIN_PASSWORD,
})).token;
async function register(suffix) {
  return api('POST', '/register', null, {
    username: `modules${run}${suffix}`, email: `modules${run}${suffix}@example.com`,
    password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!',
  }, 201);
}
const owner = await register('a');
const other = await register('b');
const category = (await api('POST', '/admin/categories', admin, { name: `Modules ${run}` }, 201)).data;
const attributes = {
  title: `Modules ${run}`, description: 'Disposable module integration fixture',
  price: '4.25', stock_quantity: 5, category_id: category.id,
};
const product = (await api('POST', '/admin/products', admin, attributes, 201)).data;
await api('POST', `/cart/${product.id}`, owner.token, { quantity: 2 });
await api('POST', `/cart/${product.id}`, other.token, { quantity: 1 });
const preview = (await api('GET', '/checkout', owner.token)).data;
assert.equal(preview.customer.id, owner.data.id);
assert.equal(preview.items[0].product.title, attributes.title);
assert.equal(preview.items[0].product.category.name, category.name);
assert.equal(preview.subtotal, '8.50');
const order = (await api('POST', '/orders', owner.token, undefined, 201)).data;
assert.equal(order.customer.id, owner.data.id);
assert.equal(order.items[0].product_title, attributes.title);
assert.equal(order.items[0].unit_price, '4.25');
const blocked = await api('DELETE', `/admin/products/${product.id}`, admin, undefined, 409);
assert.match(blocked.message, /1 pending order/);
await api('GET', `/products/${product.id}`);
await api('GET', `/orders/${order.id}`, other.token, undefined, 403);

const adminOrders = (await api('GET', '/admin/orders', admin)).data;
assert.equal(adminOrders.find((o) => o.id === order.id).customer.email, owner.data.email);
const dashboard = await api('GET', '/admin/dashboard', admin);
assert.equal(dashboard.recent_orders.find((o) => o.id === order.id).customer.id, owner.data.id);
const adminNotices = (await api('GET', '/notifications', admin)).data;
assert.ok(adminNotices.some((n) => n.type === 'new_order_received' && n.data.order_id === order.id));
const firstNotice = (await api('GET', '/notifications', owner.token)).data.find((n) => n.data.order_id === order.id);
assert.equal(firstNotice.data.status, 'pending');
await api('PATCH', `/notifications/${firstNotice.id}/read`, other.token, undefined, 404);

await api('PATCH', `/admin/orders/${order.id}/status`, admin, { status: 'completed' });
await api('PATCH', `/admin/orders/${order.id}/status`, admin, { status: 'completed' });
const notices = (await api('GET', '/notifications', owner.token)).data;
const changes = notices.filter((n) => n.type === 'order_status_changed' && n.data.order_id === order.id);
assert.equal(changes.length, 1);
assert.equal(changes[0].data.previous_status, 'pending');
assert.equal(changes[0].data.status, 'completed');
// The old event snapshot must still describe the committed pending order.
assert.equal(notices.find((n) => n.id === firstNotice.id).data.status, 'pending');
await api('DELETE', `/admin/products/${product.id}`, admin);
await api('GET', `/products/${product.id}`, null, undefined, 404);
assert.equal((await api('GET', '/cart', other.token)).data.item_count, 0);
const history = (await api('GET', `/orders/${order.id}`, owner.token)).data;
assert.equal(history.items[0].product_title, attributes.title);
assert.equal(history.customer.email, owner.data.email);
await api('PATCH', `/notifications/${firstNotice.id}/read`, owner.token);
await api('POST', '/notifications/read-all', owner.token);
assert.equal((await api('GET', '/notifications?unread=1', owner.token)).meta.unread_count, 0);
await api('POST', '/cart/not-a-number', owner.token, { quantity: 1 }, 404);
await api('POST', '/cart/99999999', owner.token, { quantity: 1 }, 404);
const unused = (await api('POST', '/admin/products', admin, { ...attributes, title: `Unused ${run}`, stock_quantity: 0 }, 201)).data;
await api('DELETE', `/admin/products/${unused.id}`, admin);
await api('GET', `/products/${unused.id}`, null, undefined, 404);
console.log('PASS: catalog/profile snapshots, pending-order deletion guard, historical product titles, cart cleanup, admin views/notifications, immutable event data and notification ownership/read state.');
