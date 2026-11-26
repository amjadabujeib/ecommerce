// Disposable stack verification for Requirement 5 (Scalability & Load Distribution).
// Run:
//   env PROJECT=novacart-scale ADMIN_PASSWORD='...' node docs/demo/verify-requirement-5.mjs

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';

const base = (process.env.BASE_URL ?? 'http://localhost:8080').replace(/\/$/, '');
const apiBase = `${base}/api/v1`;
const project = process.env.PROJECT ?? 'novacart-scale';
const engine = process.env.CONTAINER_ENGINE ?? 'docker';
assert.ok(process.env.ADMIN_PASSWORD, 'Set ADMIN_PASSWORD for the disposable stack.');

const composeFiles = [
  '-p', project,
  '-f', 'docker-compose.yml',
  '-f', 'docker-compose.realtime.yml',
  '-f', 'docker-compose.scale.yml',
];

function compose(...args) {
  return execFileSync(engine, ['compose', ...composeFiles, ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

async function api(method, path, token, body, expected = 200) {
  const isForm = body instanceof FormData;
  const response = await fetch(`${apiBase}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(isForm ? {} : { 'Content-Type': 'application/json' }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    signal: AbortSignal.timeout(10_000),
  });
  const json = await response.json();
  if (expected !== null) {
    assert.equal(response.status, expected, `${method} ${path} -> ${response.status}: ${JSON.stringify(json)}`);
  }
  return { status: response.status, headers: response.headers, json };
}

async function burst(total, concurrency, path = '/up') {
  const counts = {};
  const statuses = {};
  let next = 0;

  await Promise.all(Array.from({ length: concurrency }, async () => {
    while (true) {
      const idx = next++;
      if (idx >= total) break;
      const response = await fetch(`${base}${path}`, {
        headers: { Accept: 'application/json' },
        signal: AbortSignal.timeout(10_000),
      });
      await response.arrayBuffer();
      const server = response.headers.get('x-served-by') || 'missing';
      counts[server] = (counts[server] || 0) + 1;
      statuses[response.status] = (statuses[response.status] || 0) + 1;
    }
  }));

  return { counts, statuses };
}

// 1. 500-request load distribution proof across app-1, app-2, app-3
console.log('--- 1) Load distribution: 500 concurrent requests ---');
const dist = await burst(500, 20);
console.log('Distribution:', dist.counts, 'Statuses:', dist.statuses);
assert.equal(dist.statuses[200], 500, 'All 500 requests must succeed with HTTP 200');
for (const instance of ['app-1', 'app-2', 'app-3']) {
  assert.ok((dist.counts[instance] || 0) > 0, `Instance ${instance} received 0 requests`);
}
console.log('PASS: 500 requests distributed across all 3 instances.');

// 2. Hidden state checks (JWT across replicas, shared blacklist, shared throttling, shared uploads)
console.log('--- 2) Multi-instance shared state checks ---');
const run = randomUUID().replaceAll('-', '').slice(0, 12);
const admin = (await api('POST', '/login', null, {
  email: process.env.ADMIN_EMAIL ?? 'admin@example.com',
  password: process.env.ADMIN_PASSWORD,
})).json.token;

const reg = await api('POST', '/register', null, {
  username: `scale${run}`,
  email: `scale${run}@example.test`,
  password: 'ScalePassword123!',
  password_confirmation: 'ScalePassword123!',
}, 201);
const userToken = reg.json.token;

// Verify the same JWT succeeds across all 3 replicas
const authSeen = new Set();
for (let i = 0; i < 30; i++) {
  const res = await api('GET', '/me', userToken);
  authSeen.add(res.headers.get('x-served-by'));
}
assert.equal(authSeen.size, 3, `Expected JWT accepted on all 3 replicas, saw: ${[...authSeen]}`);

// Logout on one replica -> must be rejected (401) across ALL replicas immediately
await api('POST', '/logout', userToken);
for (let i = 0; i < 15; i++) {
  await api('GET', '/me', userToken, undefined, 401);
}
console.log('PASS: Shared JWT signing and immediate cross-replica blacklist verified.');

// Verify auth throttle (6 attempts -> 7th returns 429 across all 3 replicas)
for (let i = 0; i < 6; i++) {
  await api('POST', '/login', null, { email: `lock${run}@example.test`, password: 'wrong' }, 422);
}
await api('POST', '/login', null, { email: `lock${run}@example.test`, password: 'wrong' }, 429);
console.log('PASS: Shared rate limiter enforced 429 accurately across 3 replicas.');

// Verify uploaded product image is accessible and consistent across replicas
const category = (await api('POST', '/admin/categories', admin, { name: `Scale ${run}` }, 201)).json.data;
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const form = new FormData();
form.set('title', `Scale Product ${run}`);
form.set('description', 'Multi-instance upload check');
form.set('price', '15.00');
form.set('stock_quantity', '10');
form.set('category_id', String(category.id));
form.set('image', new Blob([png], { type: 'image/png' }), 'pixel.png');
const product = (await api('POST', '/admin/products', admin, form, 201)).json.data;
const imgRes = await fetch(`${base}/storage/${product.image_path}`);
assert.equal(imgRes.status, 200, 'Uploaded image must be served with HTTP 200');
await imgRes.arrayBuffer();
console.log('PASS: Shared storage serves uploaded product image without 404.');

// 3. Verify 3 independent workers are consuming from the same RabbitMQ queue
console.log('--- 3) 3 Workers sharing novacart.mail.v1 ---');
const queueTable = compose('exec', '-T', 'app', 'php', 'artisan', 'messaging:dlq', 'mail');
console.log(queueTable);
assert.match(queueTable, /novacart\.mail\.v1\s+\|\s+\d+\s+\|\s+3\s+\|/, 'Expected 3 active consumers on novacart.mail.v1');
console.log('PASS: 3 mail workers actively share novacart.mail.v1.');

// 4. Failover proof: stop app-2 during active traffic, then restore it
console.log('--- 4) Failover: stopping app-2 under load ---');
try {
  const trafficPromise = burst(300, 15);
  await sleep(150);
  compose('stop', 'app-2');
  const duringOutage = await trafficPromise;
  console.log('During outage:', duringOutage.counts, 'Statuses:', duringOutage.statuses);
  assert.equal(duringOutage.statuses[200], 300, 'Zero requests may fail when app-2 stops');

  const afterStop = await burst(60, 10);
  console.log('After app-2 stopped:', afterStop.counts, 'Statuses:', afterStop.statuses);
  assert.equal(afterStop.statuses[200], 60);
  assert.equal(afterStop.counts['app-2'] || 0, 0, 'Stopped app-2 must receive 0 requests');
  assert.ok((afterStop.counts['app-1'] || 0) > 0 && (afterStop.counts['app-3'] || 0) > 0);
  console.log('PASS: System continued serving 100% of requests via app-1 and app-3 while app-2 was stopped.');
} finally {
  compose('start', 'app-2');
}

// Wait for Nginx resolve (valid=5s) + fail_timeout (10s) and verify automatic recovery
let recovered = false;
for (let attempt = 0; attempt < 15; attempt++) {
  const probe = await burst(30, 10);
  if ((probe.counts['app-2'] || 0) > 0) {
    recovered = true;
    console.log('Recovered distribution:', probe.counts);
    break;
  }
  await sleep(1500);
}
assert.ok(recovered, 'app-2 did not rejoin the load balancer pool after restart');
console.log('PASS: app-2 automatically rejoined the pool after restart.');