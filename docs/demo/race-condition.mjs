// Requirement 2 proof (slide 13): several customers order the LAST unit of a
// product at the same moment. Exactly one order may succeed.
//
// Runs against the real stack (docker compose up), through the public API:
//
//   ADMIN_PASSWORD='...' node docs/demo/race-condition.mjs
//
// Options (environment variables):
//   BASE_URL        default http://localhost:8080/api/v1
//   ADMIN_EMAIL     default admin@example.com
//   ADMIN_PASSWORD  required (see `docker compose logs app` if it was generated)
//   BUYERS          default 10
//
// Needs Node.js 18+ (built-in fetch). No packages to install.

const BASE_URL = process.env.BASE_URL ?? 'http://localhost:8080/api/v1';
const ADMIN_EMAIL = process.env.ADMIN_EMAIL ?? 'admin@example.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD;
const BUYERS = Number(process.env.BUYERS ?? 10);

if (!ADMIN_PASSWORD) {
  console.error('Set ADMIN_PASSWORD (the seeded administrator password).');
  process.exit(1);
}

async function api(method, path, { token, body } = {}) {
  const response = await fetch(`${BASE_URL}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  return { status: response.status, json: await response.json().catch(() => ({})) };
}

async function expectOk(promise, what) {
  const result = await promise;

  if (result.status >= 300) {
    throw new Error(`${what} failed: ${result.status} ${JSON.stringify(result.json)}`);
  }

  return result.json;
}

const admin = await expectOk(
  api('POST', '/login', { body: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } }),
  'Admin login',
);

const catalogue = await expectOk(api('GET', '/?page=1'), 'Product list');
const product = catalogue.data[0];
const originalStock = product.stock_quantity;

console.log(`Product: #${product.id} "${product.title}" (stock before the demo: ${originalStock})`);

// 1. Leave exactly one unit.
await expectOk(
  api('PATCH', `/admin/products/${product.id}/stock`, { token: admin.token, body: { stock_quantity: 1 } }),
  'Set stock',
);
console.log('Stock set to 1 unit.\n');

// 2. Every buyer registers and puts that last unit in their cart.
const runId = Date.now();
const buyers = [];

for (let i = 1; i <= BUYERS; i++) {
  const { token } = await expectOk(
    api('POST', '/register', {
      body: {
        username: `racer${runId}${i}`,
        email: `racer-${runId}-${i}@example.com`,
        password: 'Password123!',
        password_confirmation: 'Password123!',
      },
    }),
    `Register buyer ${i}`,
  );

  await expectOk(
    api('POST', `/cart/${product.id}`, { token, body: { quantity: 1 } }),
    `Buyer ${i} add to cart`,
  );

  buyers.push({ name: `Buyer ${String(i).padStart(2)}`, token });
}

console.log(`${BUYERS} buyers have the last unit in their cart. Checking out at the same moment...\n`);

// 3. All checkouts fire together.
const results = await Promise.all(
  buyers.map(async (buyer) => ({ buyer, ...(await api('POST', '/orders', { token: buyer.token })) })),
);

for (const { buyer, status, json } of results) {
  const outcome = status === 201 ? `order #${json.data.id} placed` : json.message;
  console.log(`${buyer.name}  ${status}  ${outcome}`);
}

const placed = results.filter((r) => r.status === 201).length;
const rejected = results.filter((r) => r.status === 409).length;
const after = await expectOk(api('GET', `/products/${product.id}`), 'Product detail');

console.log(`\nPlaced: ${placed}   Rejected (409): ${rejected}   Stock now: ${after.data.stock_quantity}`);
console.log(placed === 1 && after.data.stock_quantity === 0 ? 'PASS: only one order won the last unit.' : 'FAIL');

// 4. Put the stock back as it was.
await expectOk(
  api('PATCH', `/admin/products/${product.id}/stock`, {
    token: admin.token,
    body: { stock_quantity: originalStock ?? 25 },
  }),
  'Restore stock',
);
console.log(`Stock restored to ${originalStock ?? 25}.`);

process.exit(placed === 1 ? 0 : 1);
