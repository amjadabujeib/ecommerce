// Requirement 2 proof through the real HTTP API. Use a disposable test stack.
// Creates a dedicated category/product and BUYERS accounts, then verifies the
// last-unit invariant. Finally cancels fixture orders and removes the catalog
// fixtures. Accounts/notifications remain until the disposable DB is removed.
//
// fish: env ADMIN_PASSWORD='...' node docs/demo/race-condition.mjs
// BASE_URL defaults to http://localhost:8080/api/v1; ADMIN_EMAIL to admin@example.com.
// BUYERS defaults to 10 (2–20). No packages required; Node.js 18+.

import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const baseUrl = (process.env.BASE_URL ?? 'http://localhost:8080/api/v1').replace(/\/$/, '');
const adminPassword = process.env.ADMIN_PASSWORD;
const buyerCount = Number(process.env.BUYERS ?? 10);
assert.ok(adminPassword, 'Set ADMIN_PASSWORD for the disposable test stack.');
assert.ok(Number.isInteger(buyerCount) && buyerCount >= 2 && buyerCount <= 20, 'BUYERS must be an integer from 2 to 20.');

async function api(method, path, { token, body } = {}) {
  const response = await fetch(`${baseUrl}${path}`, {
    method,
    signal: AbortSignal.timeout(30_000),
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  return { status: response.status, json: await response.json() };
}

async function expectStatus(promise, status, description) {
  const result = await promise;
  assert.equal(result.status, status, `${description}: expected ${status}, got ${result.status}`);
  return result.json;
}

const buyers = [];
let adminToken;
let categoryId;
let productId;
let failed = false;

try {
  const admin = await expectStatus(api('POST', '/login', { body: {
    email: process.env.ADMIN_EMAIL ?? 'admin@example.com', password: adminPassword,
  } }), 200, 'Admin login');
  adminToken = admin.token;
  const runId = randomUUID().replaceAll('-', '');
  const category = await expectStatus(api('POST', '/admin/categories', {
    token: adminToken, body: { name: `Race ${runId}` },
  }), 201, 'Create fixture category');
  categoryId = category.data.id;
  const product = await expectStatus(api('POST', '/admin/products', {
    token: adminToken,
    body: { title: `Race ${runId}`, description: 'Disposable last-unit proof', price: '1.00', category_id: categoryId, stock_quantity: 1 },
  }), 201, 'Create fixture product');
  productId = product.data.id;
  const catalog = await expectStatus(api('GET', `/?category=${categoryId}`), 200, 'Read catalog choices');
  assert.ok(catalog.categories.some((entry) => entry.id === categoryId), 'Named category choices include the fixture.');
  assert.equal(typeof catalog.currency, 'string');

  for (let i = 0; i < buyerCount; i++) {
    const account = await expectStatus(api('POST', '/register', { body: {
      username: `race${runId}${i}`, email: `race-${runId}-${i}@example.com`,
      password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!',
    } }), 201, `Register buyer ${i + 1}`);
    buyers.push({ token: account.token });
    await expectStatus(api('POST', `/cart/${productId}`, { token: account.token, body: { quantity: 1 } }), 200, 'Prepare cart');
  }

  // Await every request before assertions or cleanup, including transport failures.
  const attempts = await Promise.allSettled(buyers.map(({ token }) => api('POST', '/orders', { token })));
  assert.ok(attempts.every((r) => r.status === 'fulfilled'), 'All checkout requests must receive an HTTP response.');
  const results = attempts.map((r) => r.value);
  const winners = results.filter((r) => r.status === 201);
  const losers = results.filter((r) => r.status === 409);
  assert.equal(winners.length, 1, 'Exactly one checkout must succeed.');
  assert.equal(losers.length, buyerCount - 1, 'Every other checkout must return 409.');
  for (const loser of losers) {
    assert.equal(loser.json.status, 409);
    assert.equal(loser.json.code, 'conflict');
  }
  const after = await expectStatus(api('GET', `/products/${productId}`), 200, 'Read final stock');
  assert.equal(after.data.stock_quantity, 0, 'Final inventory must be exactly zero.');

  const savedOrders = [];
  for (const buyer of buyers) {
    const orders = await expectStatus(api('GET', '/orders', { token: buyer.token }), 200, 'Check persisted orders');
    savedOrders.push(...orders.data);
  }
  assert.equal(savedOrders.length, 1, 'Exactly one order must be persisted across all new buyers.');
  assert.equal(savedOrders[0].id, winners[0].json.data.id);
  assert.equal(savedOrders[0].items.length, 1);
  assert.equal(savedOrders[0].items[0].quantity, 1);
  console.log(`PASS: ${buyerCount} buyers, one 201, ${losers.length} conflicts, one persisted order, stock 0.`);
} catch (error) {
  failed = true;
  console.error(`FAIL: ${error.message}`);
} finally {
  // Discover persisted orders even if the checkout's response was lost.
  let canDeleteProduct = true;
  for (const buyer of buyers) {
    try {
      const orders = await expectStatus(api('GET', '/orders', { token: buyer.token }), 200, 'Cleanup: list orders');
      for (const order of orders.data) {
        await expectStatus(api('PATCH', `/admin/orders/${order.id}/status`, {
          token: adminToken, body: { status: 'cancelled' },
        }), 200, 'Cleanup: cancel order');
      }
      await expectStatus(api('DELETE', '/cart', { token: buyer.token }), 200, 'Cleanup: clear cart');
    } catch (error) {
      failed = true;
      canDeleteProduct = false;
      console.error(`Cleanup incomplete: ${error.message}`);
    }
  }
  if (productId && canDeleteProduct) {
    try {
      await expectStatus(api('PATCH', `/admin/products/${productId}/stock`, {
        token: adminToken, body: { stock_quantity: 0 },
      }), 200, 'Cleanup: zero fixture inventory');
      await expectStatus(api('DELETE', `/admin/products/${productId}`, { token: adminToken }), 200, 'Cleanup: delete product');
      // Products referenced by historical orders are soft-deleted; retain their category.
      console.log(`Fixture product ${productId} removed; category/accounts/history remain in the disposable database.`);
    } catch (error) {
      failed = true;
      console.error(`Cleanup incomplete for product ${productId}: ${error.message}`);
    }
  } else if (categoryId && !productId) {
    try {
      await expectStatus(api('DELETE', `/admin/categories/${categoryId}`, { token: adminToken }), 200, 'Cleanup: delete empty category');
    } catch (error) {
      failed = true;
      console.error(`Cleanup incomplete for category ${categoryId}: ${error.message}`);
    }
  }
}
process.exitCode = failed ? 1 : 0;
