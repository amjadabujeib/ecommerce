// Disposable stack only. Temporarily replaces INVENTORY_CONTAINER with the
// same image/environment and a 1500ms artificial RPC delay. Restores it in finally.
// Use an app INVENTORY_TIMEOUT_MS=500; ADMIN_PASSWORD and BASE_URL are required.
import assert from 'node:assert/strict';
import {execFileSync as exec} from 'node:child_process';
import {setTimeout as sleep} from 'node:timers/promises';
import {randomUUID} from 'node:crypto';
const base = process.env.BASE_URL || 'http://127.0.0.1:8080/api/v1';
const name = process.env.INVENTORY_CONTAINER;
assert.ok(name && process.env.ADMIN_PASSWORD, 'Set INVENTORY_CONTAINER and ADMIN_PASSWORD.');
const engine = process.env.CONTAINER_ENGINE || 'docker';
const run = args => exec(engine, args, {encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']});
const original = JSON.parse(run(['inspect', name]))[0];
const network = Object.keys(original.NetworkSettings.Networks)[0];
assert.equal(Object.keys(original.NetworkSettings.Networks).length, 1, 'Use an isolated single-network test container.');
async function api(method, path, token, body, expected = 200) {
    const response = await fetch(base + path, {method, headers: {Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {})}, body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(10000)});
    const json = await response.json();
    assert.equal(response.status, expected, `${method} ${path}: ${JSON.stringify(json)}`);
    return {response, json};
}
const admin = (await api('POST', '/login', null, {email: process.env.ADMIN_EMAIL || 'admin@example.com', password: process.env.ADMIN_PASSWORD})).json.token;
const id = randomUUID().replaceAll('-', '');
const customer = (await api('POST', '/register', null, {username: `slow${id}`, email: `slow${id}@example.com`, password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!'}, 201)).json.token;
const category = (await api('POST', '/admin/categories', admin, {name: `Slow ${id}`}, 201)).json.data;
const product = (await api('POST', '/admin/products', admin, {title: `Slow ${id}`, description: 'Disposable delayed inventory fixture', price: '1.00', stock_quantity: 3, category_id: category.id}, 201)).json.data;
await api('POST', `/cart/${product.id}`, customer, {quantity: 1});
let renamed = false, delayed = false;
try {
    run(['stop', name]); run(['rename', name, `${name}-normal`]); renamed = true;
    const args = ['run', '--pull=never', '-d', '--name', name, '--network', network, '--network-alias', 'inventory'];
    for (const variable of original.Config.Env.filter(value => !value.startsWith('INVENTORY_ARTIFICIAL_DELAY_MS='))) args.push('-e', variable);
    args.push('-e', 'INVENTORY_ARTIFICIAL_DELAY_MS=1500', original.Image);
    run(args); delayed = true;
    for (let attempt = 0; attempt < 30; attempt++) {
        try { run(['exec', name, 'node', '-e', "const net=require('node:net');const socket=net.connect(50051,'127.0.0.1',()=>{socket.end()});socket.on('error',()=>process.exit(1));"]); break; }
        catch { await sleep(100); }
    }
    const start = Date.now();
    const {json, response} = await api('POST', '/orders', customer, undefined, 503);
    const elapsed = Date.now() - start;
    assert.equal(json.code, 'service_unavailable'); assert.equal(response.headers.get('retry-after'), '5');
    assert.ok(elapsed < 3000, `Checkout exceeded bounded test deadline: ${elapsed}ms`);
    assert.equal((await api('GET', '/orders', customer)).json.data.length, 0);
    await sleep(3500); // Let timed-out Reserve and Release reach their handlers.
    console.log(`PASS: 1500ms RPC delay -> structured 503 in ${elapsed}ms; no order persisted.`);
} finally {
    if (delayed) run(['rm', '-f', name]);
    if (renamed) { run(['rename', `${name}-normal`, name]); run(['start', name]); }
    for (let attempt = 0; attempt < 30; attempt++) {
        try {
            const current = (await api('GET', `/products/${product.id}`)).json.data;
            if (current.stock_quantity !== null) {
                assert.equal(current.stock_quantity, 3, 'Late reservation leaked stock.');
                console.log('PASS: delayed reservation/compensation settled with all stock restored.');
                break;
            }
        } catch (error) { if (attempt === 29) throw error; }
        if (attempt === 29) throw new Error('Original inventory failed to recover.');
        await sleep(200);
    }
    await api('DELETE', '/cart', customer);
    await api('PATCH', `/admin/products/${product.id}/stock`, admin, {stock_quantity: 0});
    await api('DELETE', `/admin/products/${product.id}`, admin);
}
