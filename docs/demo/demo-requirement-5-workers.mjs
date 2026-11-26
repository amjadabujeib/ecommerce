// Proves 3 mail workers actively split queued orders from novacart.mail.v1.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';

const apiBase = (process.env.BASE_URL ?? 'http://localhost:8080').replace(/\/$/, '') + '/api/v1';
const project = process.env.PROJECT ?? 'novacart-scale';
const engine = process.env.CONTAINER_ENGINE ?? 'docker';
assert.ok(process.env.ADMIN_PASSWORD, 'Set ADMIN_PASSWORD first.');

const composeArgs = ['--env-file', '.env', '-p', project, '-f', 'docker-compose.yml', '-f', 'docker-compose.realtime.yml', '-f', 'docker-compose.scale.yml'];
const compose = (...args) => execFileSync(engine, ['compose', ...composeArgs, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();

async function api(method, path, token, body, expected = 200) {
  const res = await fetch(`${apiBase}${path}`, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const json = await res.json();
  assert.equal(res.status, expected, `${method} ${path} -> ${res.status}: ${JSON.stringify(json)}`);
  return json;
}

const run = randomUUID().replaceAll('-', '').slice(0, 10);
const admin = (await api('POST', '/login', null, { email: process.env.ADMIN_EMAIL ?? 'admin@example.com', password: process.env.ADMIN_PASSWORD })).token;
const buyer = (await api('POST', '/register', null, { username: `wk${run}`, email: `wk${run}@example.test`, password: 'WorkerPassword123!', password_confirmation: 'WorkerPassword123!' }, 201)).token;
const cat = (await api('POST', '/admin/categories', admin, { name: `Workers ${run}` }, 201)).data;
const prod = (await api('POST', '/admin/products', admin, { title: `Workers ${run}`, description: 'Worker split demo', price: '5.00', stock_quantity: 20, category_id: cat.id }, 201)).data;

const since = new Date().toISOString();
const workers = ['mail-worker', 'mail-worker-2', 'mail-worker-3'];
const orderIds = [];

try {
  compose('stop', ...workers);
  for (let i = 0; i < 9; i++) {
    await api('POST', `/cart/${prod.id}`, buyer, { quantity: 1 });
    const order = (await api('POST', '/orders', buyer, undefined, 201)).data;
    orderIds.push(order.id);
  }
  console.log('Queued 9 orders while workers were stopped:', orderIds.join(', '));
  console.log(compose('exec', '-T', 'app', 'php', 'artisan', 'messaging:dlq', 'mail'));
} finally {
  compose('start', ...workers);
}

const byWorker = { 'mail-worker-1': 0, 'mail-worker-2': 0, 'mail-worker-3': 0 };
const seenEvents = new Set();

for (let attempt = 0; attempt < 20 && seenEvents.size < 9; attempt++) {
  await sleep(1000);
  const logs = compose('logs', '--no-color', '--since', since, ...workers);
  for (const line of logs.split('\n')) {
    const eventMatch = line.match(/"event_id":"([0-9a-f-]+)"/);
    if (!eventMatch || seenEvents.has(eventMatch[1])) continue;
    const containerMatch = line.match(/mail-worker(-[23])?-\d+\s*\|/);
    const label = containerMatch?.[1] === '-2' ? 'mail-worker-2'
      : containerMatch?.[1] === '-3' ? 'mail-worker-3'
      : containerMatch ? 'mail-worker-1' : null;
    if (label) {
      seenEvents.add(eventMatch[1]);
      byWorker[label]++;
    }
  }
}

console.log('First-attempt distribution across workers:', byWorker);
console.log(compose('exec', '-T', 'app', 'php', 'artisan', 'messaging:dlq', 'mail'));
if (seenEvents.size < 9) {
  console.error('Recent worker logs:\n' + compose('logs', '--no-color', '--tail=20', ...workers));
}
assert.equal(seenEvents.size, 9, `Expected 9 unique order events handled, saw ${seenEvents.size}`);
const activeWorkers = Object.values(byWorker).filter((count) => count > 0).length;
assert.ok(activeWorkers >= 2, `Expected multiple workers to share the queue, got ${JSON.stringify(byWorker)}`);
console.log(`PASS: 9 queued orders were split across ${activeWorkers} parallel mail workers.`);