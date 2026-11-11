// Dependency-free browser acceptance test. Start a disposable Brave/Chromium
// with --headless --remote-debugging-port=19223 and a temporary user-data-dir.
// ADMIN_PASSWORD, BASE_URL (shop origin), CDP_URL (default localhost:19223).
import assert from 'node:assert/strict';
import {mkdtemp, writeFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {setTimeout as sleep} from 'node:timers/promises';
const base = process.env.BASE_URL || 'http://127.0.0.1:8080';
const cdp = process.env.CDP_URL || 'http://127.0.0.1:19223';
assert.ok(process.env.ADMIN_PASSWORD, 'Set a disposable ADMIN_PASSWORD.');
const page = await (await fetch(`${cdp}/json/new?about:blank`, {method: 'PUT'})).json();
assert.ok(page, 'Open a browser tab first.');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise(resolve => ws.addEventListener('open', resolve, {once: true}));
let id = 0;
const pending = new Map(); const errors = [];
let heldCatalogResponse;
ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id) {
        const promise = pending.get(message.id); pending.delete(message.id);
        if (message.error) promise.reject(new Error(message.error.message)); else promise.resolve(message.result);
    }
    if (message.method === 'Fetch.requestPaused') heldCatalogResponse = message.params.requestId;
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
    await evaluate(`(() => { const form = document.querySelector(${JSON.stringify(selector)}); form.requestSubmit(form.querySelector('button:not([type]), button[type="submit"]')); })()`);
}
const run = Date.now().toString();
const fixtureDirectory = await mkdtemp(join(tmpdir(), 'novacart-browser-'));
const imagePath = join(fixtureDirectory, 'product.png');
await writeFile(imagePath, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
try {
    for (let attempt = 0; attempt < 100; attempt++) {
        try {
            const response = await fetch(base);
            if (response.ok && (await response.text()).includes('id="product-detail"')) break;
        } catch {}
        if (attempt === 99) throw new Error('The updated storefront did not become ready.');
        await sleep(200);
    }
    await send('Runtime.enable'); await send('Log.enable'); await send('Page.enable');
    await send('Page.bringToFront');
    await send('Emulation.setDeviceMetricsOverride', {width: 1280, height: 900, deviceScaleFactor: 1, mobile: false});
    await send('Network.enable'); await send('Network.setCacheDisabled', {cacheDisabled: true});
    await send('Page.navigate', {url: base});
    await wait(`document.querySelector('#products .card') && document.querySelector('#loading').hidden`);
    await click('[data-view="account"]');
    await form('#login', {email: 'admin@example.com', password: process.env.ADMIN_PASSWORD});
    await wait(`!document.querySelector('#admin').hidden && document.querySelector('#dashboard').textContent.toLowerCase().includes('products')`);
    await click('[data-admin-tab="categories"]');
    await wait(`!document.querySelector('#admin-category-panel').hidden`);
    await form('#category-form', {name: `Browser ${run}`});
    await wait(`document.querySelector('#categories').textContent.includes('Browser ${run}')`);
    await click('[data-admin-tab="products"]');
    await click('#new-product');
    await wait(`document.querySelector('#product-editor').open && document.querySelector('#product-form select').textContent.includes('Browser ${run}')`);
    const category = await evaluate(`Array.from(document.querySelector('#product-form select').options).find(option => option.textContent === 'Browser ${run}').value`);
    const {root} = await send('DOM.getDocument');
    const {nodeId} = await send('DOM.querySelector', {nodeId: root.nodeId, selector: '#product-form [name=image]'});
    await send('DOM.setFileInputFiles', {nodeId, files: [imagePath]});
    await form('#product-form', {title: `Browser product ${run}`, description: 'Browser acceptance fixture', price: '12.50', stock_quantity: '3', category_id: category});
    await wait(`document.querySelector('#admin-products').textContent.includes('Browser product ${run}')`);
    await evaluate(`document.querySelector('#admin-products img').scrollIntoView()`);
    await wait(`document.querySelector('#admin-products img')?.naturalWidth > 0`);
    console.log('PASS admin GUI: sign in, category creation, product/image creation and rendering, dashboard');
    await click('#logout'); await wait(`document.querySelector('#notice-text').textContent === 'Signed out.'`);
    await click('[data-view="account"]');
    // Hold the initial catalog response so a later filtered request finishes first.
    await send('Fetch.enable', {patterns: [{urlPattern: `${base}/api/v1/?page=1`, requestStage: 'Response'}]});
    await click('[data-auth-tab="register"]');
    await form('#register', {username: `browser${run}`, email: `browser${run}@example.com`, password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!'});
    await wait(`document.querySelector('#account-link').textContent === 'browser${run}'`);
    for (let attempt = 0; attempt < 100 && !heldCatalogResponse; attempt++) await sleep(50);
    assert.ok(heldCatalogResponse, 'Initial catalog response was intercepted.');
    await form('#filters', {q: `Browser product ${run}`, category});
    await wait(`document.querySelectorAll('#products .card').length === 1 && document.querySelector('#products').textContent.includes('Browser product ${run}')`);
    await send('Fetch.continueRequest', {requestId: heldCatalogResponse});
    await send('Fetch.disable');
    await wait(`!document.querySelector('#register button').disabled`);
    assert.equal(await evaluate(`document.querySelectorAll('#products .card').length`), 1, 'An older catalog response must not overwrite the filtered results.');
    console.log('PASS catalog GUI: a delayed earlier response preserves the newer search results.');
    await click('#products .product-title');
    await wait(`!document.querySelector('#product').hidden && document.querySelector('#product-detail h1')?.textContent === 'Browser product ${run}'`);
    assert.equal(await evaluate(`document.querySelector('#product-detail .description').textContent`), 'Browser acceptance fixture');
    assert.ok((await evaluate('location.hash')).startsWith('#/products/'));
    await evaluate('history.back()');
    await wait(`!document.querySelector('#catalog').hidden && document.querySelectorAll('#products .card').length === 1`);
    await evaluate('history.forward()');
    await wait(`!document.querySelector('#product').hidden && document.querySelector('#product-purchase')`);
    await send('Page.reload');
    await wait(`document.querySelector('#product-detail h1')?.textContent === 'Browser product ${run}'`);
    await form('#product-purchase', {quantity: '2'});
    await wait(`document.querySelector('#notice-text').textContent.includes('added to your cart') && document.querySelector('#cart-count').textContent === '2'`);
    console.log('PASS product usability: dedicated detail screen, browser back/forward, deep-link reload, quantity selection and cart badge.');
    await click('[data-view="cart"]');
    await wait(`document.querySelector('#cart-content').textContent.includes('Subtotal: $25.00')`);
    await fill('#cart-content input', '1');
    await evaluate(`Array.from(document.querySelectorAll('#cart-content button')).find(button => button.textContent === 'Update').click()`);
    await wait(`document.querySelector('#cart-content').textContent.includes('Subtotal: $12.50') && document.querySelector('#cart-count').textContent === '1'`);
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
    await wait(`!document.querySelector('#admin').hidden`);
    await click('[data-admin-tab="orders"]');
    await wait(`!document.querySelector('#admin-order-panel').hidden && document.querySelector('#admin-orders').textContent.includes('browser${run}')`);
    await evaluate(`Array.from(document.querySelectorAll('#admin-orders article')).find(card => card.textContent.includes('browser${run}')).querySelector('button').click()`);
    await wait(`Array.from(document.querySelectorAll('#admin-orders article')).find(card => card.textContent.includes('browser${run}'))?.textContent.includes('Completed')`);
    console.log('PASS admin GUI: customer order visibility and status change');
    await click('[data-admin-tab="products"]');
    await wait(`document.querySelector('#admin-products').textContent.includes('Browser product ${run}')`);
    await evaluate(`Array.from(document.querySelectorAll('#admin-products .card')).find(card => card.textContent.includes('Browser product ${run}')).querySelector('button').click()`);
    await wait(`document.querySelector('#product-editor').open`);
    await form('#product-form', {price: '13.00'});
    await wait(`!document.querySelector('#product-editor').open && document.querySelector('#admin-products').textContent.includes('$13.00')`);
    console.log('PASS admin usability: separate product/order/category sections and a working edit dialog.');
    await click('#logout'); await wait(`document.querySelector('#notice-text').textContent === 'Signed out.'`);
    await form('#filters', {q: `Browser product ${run}`, category});
    await wait(`document.querySelectorAll('#products .card').length === 1`);
    await click('#products .product-title');
    await wait(`document.querySelector('#product-purchase button')?.textContent === 'Sign in to add to cart'`);
    await form('#product-purchase', {quantity: '1'});
    await wait(`!document.querySelector('#account').hidden`);
    await click('[data-auth-tab="register"]');
    await form('#register', {username: `guest${run}`, email: `guest${run}@example.com`, password: 'VerificationOnly123!', password_confirmation: 'VerificationOnly123!'});
    await wait(`!document.querySelector('#product').hidden && document.querySelector('#cart-count').textContent === '1'`);
    console.log('PASS guest usability: signing up resumes the selected product and adds the requested item.');

    await send('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
    await wait(`document.documentElement.scrollWidth <= 390`);
    assert.deepEqual(errors, [], 'Browser errors');
    console.log('PASS responsive layout: no horizontal overflow at 390px; no browser runtime/CSP errors');
} finally {
    ws.close();
    await fetch(`${cdp}/json/close/${page.id}`).catch(() => {});
    await rm(fixtureDirectory, {recursive: true, force: true});
}
