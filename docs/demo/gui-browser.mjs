// Dependency-free browser acceptance test. Start a disposable Brave/Chromium
// with --headless --remote-debugging-port=19223 and a temporary user-data-dir.
// ADMIN_PASSWORD, BASE_URL (shop origin), CDP_URL (default localhost:19223).
import assert from 'node:assert/strict';
import {setTimeout as sleep} from 'node:timers/promises';
const base = process.env.BASE_URL || 'http://127.0.0.1:8080';
const cdp = process.env.CDP_URL || 'http://127.0.0.1:19223';
assert.ok(process.env.ADMIN_PASSWORD, 'Set a disposable ADMIN_PASSWORD.');
const pages = await (await fetch(`${cdp}/json/list`)).json();
const page = pages.find(page => page.type === 'page');
assert.ok(page, 'Open a browser tab first.');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise(resolve => ws.addEventListener('open', resolve, {once: true}));
let id = 0;
const pending = new Map(); const errors = [];
ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id) {
        const promise = pending.get(message.id); pending.delete(message.id);
        if (message.error) promise.reject(new Error(message.error.message)); else promise.resolve(message.result);
    }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if (message.method === 'Log.entryAdded' && message.params.entry.level === 'error') errors.push(message.params.entry.text);
});
function send(method, params = {}) {
    return new Promise((resolve, reject) => { const request = ++id; pending.set(request, {resolve, reject}); ws.send(JSON.stringify({id: request, method, params})); });
}
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
}
async function wait(expression) {
    for (let attempt = 0; attempt < 150; attempt++) { if (await evaluate(expression)) return; await sleep(100); }
    throw new Error(`Timed out: ${expression}\n${await evaluate('document.body.innerText')}`);
}
const click = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
const fill = (selector, value) => evaluate(`document.querySelector(${JSON.stringify(selector)}).value = ${JSON.stringify(value)}`);
async function form(selector, values) {
    for (const [name, value] of Object.entries(values)) await fill(`${selector} [name="${name}"]`, value);
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`);
}
const run = Date.now().toString();
try {
    await send('Runtime.enable'); await send('Log.enable'); await send('Page.enable');
    await send('Page.navigate', {url: base});
    await wait(`document.querySelector('#products .card') && !document.querySelector('#products button')?.disabled`);
    await click('[data-view="account"]');
    await form('#login', {email: 'admin@example.com', password: process.env.ADMIN_PASSWORD});
    await wait(`!document.querySelector('#admin').hidden && document.querySelector('#dashboard').textContent.includes('products')`);
    await form('#category-form', {name: `Browser ${run}`});
    await wait(`document.querySelector('#product-form select').textContent.includes('Browser ${run}')`);
    const category = await evaluate(`Array.from(document.querySelector('#product-form select').options).find(option => option.textContent === 'Browser ${run}').value`);
    await form('#product-form', {title: `Browser product ${run}`, description: 'Browser acceptance fixture', price: '12.50', stock_quantity: '3', category_id: category});
    await wait(`document.querySelector('#admin-products').textContent.includes('Browser product ${run}')`);
    console.log('PASS admin GUI: sign in, category creation, product creation, dashboard');
    await click('#logout'); await wait(`document.querySelector('#logout').hidden`);
    await click('[data-view="account"]');
    await form('#register', {username: `browser${run}`, email: `browser${run}@example.com`, password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!'});
    await wait(`document.querySelector('#account-link').textContent === 'browser${run}'`);
    await form('#filters', {q: `Browser product ${run}`, category});
    await wait(`document.querySelectorAll('#products .card').length === 1 && document.querySelector('#products').textContent.includes('Browser product ${run}')`);
    await evaluate(`Array.from(document.querySelectorAll('#products button')).find(button => button.textContent === 'Add to cart').click()`);
    await wait(`document.querySelector('#notice').textContent.includes('added to your cart')`);
    await click('[data-view="cart"]');
    await wait(`document.querySelector('#cart-content').textContent.includes('Subtotal: 12.50')`);
    await evaluate(`Array.from(document.querySelectorAll('#cart-content button')).find(button => button.textContent === 'Place order').click()`);
    await wait(`!document.querySelector('#orders').hidden && document.querySelector('#order-list').textContent.includes('Pending')`);
    console.log('PASS customer GUI: registration, search, category filter, cart, checkout, own orders');
    await click('[data-view="notifications"]');
    await wait(`document.querySelector('#notification-list').textContent.includes('order')`);
    await click('#read-all'); await wait(`document.querySelector('#notification-list button') === null`);
    await send('Page.reload');
    await wait(`document.querySelector('#account-link')?.textContent === 'browser${run}'`);
    console.log('PASS customer GUI: notifications, read state, session survives reload');
    await click('#logout'); await wait(`document.querySelector('#logout').hidden`);
    await click('[data-view="account"]');
    await form('#login', {email: 'admin@example.com', password: process.env.ADMIN_PASSWORD});
    await wait(`!document.querySelector('#admin').hidden && document.querySelector('#admin-orders').textContent.includes('browser${run}')`);
    await evaluate(`Array.from(document.querySelectorAll('#admin-orders article')).find(card => card.textContent.includes('browser${run}')).querySelector('button').click()`);
    await wait(`Array.from(document.querySelectorAll('#admin-orders article')).find(card => card.textContent.includes('browser${run}'))?.textContent.includes('Completed')`);
    console.log('PASS admin GUI: customer order visibility and status change');
    await send('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
    await wait(`document.documentElement.scrollWidth <= 390`);
    assert.deepEqual(errors, [], 'Browser errors');
    console.log('PASS responsive layout: no horizontal overflow at 390px; no browser runtime/CSP errors');
} finally { ws.close(); }
