// Requires a disposable Chromium CDP instance on 19223 and the novacart-perf stack.
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {randomUUID} from 'node:crypto';
assert.equal(process.env.DISPOSABLE_CONFIRM, 'novacart-perf');
assert.ok(process.env.ADMIN_PASSWORD);
const base = 'http://127.0.0.1:18090', nonce = randomUUID().replaceAll('-', '');
async function api(method, path, token, body, expected = 200) {
  const response = await fetch(base + '/api/v1' + path, {method, headers: {Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {})}, body: body === undefined ? undefined : JSON.stringify(body)});
  const json = await response.json(); assert.equal(response.status, expected); return json;
}
const admin = (await api('POST', '/login', null, {email: 'admin@example.com', password: process.env.ADMIN_PASSWORD})).token;
const customer = (await api('POST', '/register', null, {username: `web${nonce}`, email: `web${nonce}@example.test`, password: 'BrowserOnly123!', password_confirmation: 'BrowserOnly123!'}, 201)).token;
const category = (await api('POST', '/admin/categories', admin, {name: `Browser ${nonce}`}, 201)).data;
const product = (await api('POST', '/admin/products', admin, {title: `Browser ${nonce}`, description: 'Disposable browser regression', price: '3.00', category_id: category.id, stock_quantity: 10}, 201)).data;
const cdp = 'http://127.0.0.1:19223';
const page = await (await fetch(cdp + '/json/new?about:blank', {method: 'PUT'})).json();
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise(resolve => ws.addEventListener('open', resolve, {once: true}));
let sequence = 0; const pending = new Map();
ws.addEventListener('message', event => { const message = JSON.parse(event.data); if (message.id) { const item = pending.get(message.id); pending.delete(message.id); if (message.error) item.reject(new Error(message.error.message)); else item.resolve(message.result); } });
function send(method, params = {}) { return new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); }); }
async function evaluate(expression) { const result = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true}); if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails)); return result.result.value; }
async function wait(expression) { for (let i = 0; i < 100; i++) { if (await evaluate(expression)) return; await new Promise(resolve => setTimeout(resolve, 100)); } throw new Error(`Browser timed out: ${expression} ${JSON.stringify(await evaluate("({url:location.href,body:document.body?.innerText.slice(0,1800)})"))}`); }
const otherOrigin = createServer((request, response) => { response.setHeader('Content-Type', 'text/html'); response.end('<!doctype html><title>Cross origin test</title>'); });
try {
  await new Promise(resolve => otherOrigin.listen(18091, '127.0.0.1', resolve));
  await send('Page.enable'); await send('Network.enable'); await send('Network.setCacheDisabled', {cacheDisabled: true});
  await send('Page.navigate', {url: base});
  await wait("document.querySelector('#products .card') && document.querySelector('#loading').hidden");
  await evaluate(`sessionStorage.setItem('novacart-token', ${JSON.stringify(admin)}); location.reload()`);
  await wait("!document.querySelector('[data-view=admin]').hidden && document.querySelector('#loading').hidden");
  await evaluate("location.hash = '#/admin/products'");
  const titleSelector = `#admin-products .product-title[href="#/products/${product.id}"]`;
  await wait(`document.querySelector(${JSON.stringify(titleSelector)})`);
  async function openEditor() {
    await evaluate(`document.querySelector(${JSON.stringify(titleSelector)}).closest('.card').querySelector('button').click()`);
    await wait("document.querySelector('#product-editor').open");
  }
  await openEditor();
  assert.equal(await evaluate("document.querySelector('#product-form').elements.stock_quantity.disabled"), true);
  assert.equal(await evaluate("new FormData(document.querySelector('#product-form')).has('stock_quantity')"), false);
  await api('POST', `/cart/${product.id}`, customer, {quantity: 2});
  await api('POST', '/orders', customer, undefined, 201);
  const title = product.title + ' changed';
  await evaluate(`(() => { const form = document.querySelector('#product-form'); form.elements.title.value = ${JSON.stringify(title)}; form.requestSubmit(form.querySelector('button')); })()`);
  await wait("!document.querySelector('#product-editor').open");
  const edited = (await api('GET', `/products/${product.id}`)).data;
  assert.equal(edited.title, title); assert.equal(edited.stock_quantity, 8);
  await openEditor();
  await evaluate("(() => { const toggle = document.querySelector('#adjust-stock'); toggle.checked = true; toggle.dispatchEvent(new Event('change')); const form = document.querySelector('#product-form'); form.elements.stock_quantity.value = '12'; form.requestSubmit(form.querySelector('button')); })()");
  await wait("!document.querySelector('#product-editor').open");
  assert.equal((await api('GET', `/products/${product.id}`)).data.stock_quantity, 12);
  console.log('PASS browser: title-only save preserves sold stock; explicit adjustment updates stock.');
  await send('Page.navigate', {url: 'http://127.0.0.1:18091'});
  await wait("document.title === 'Cross origin test'");
  const conditional = await evaluate(`(async () => { const url = ${JSON.stringify(base + '/api/v1/products/' + product.id)}; const first = await fetch(url, {cache:'no-store'}); await first.text(); const etag = first.headers.get('ETag'); if (!etag) throw new Error('ETag not exposed'); const next = await fetch(url, {cache:'no-store', headers:{'If-None-Match':etag}}); return {initial:first.status, status:next.status, body:await next.text()}; })()`);
  assert.deepEqual(conditional, {initial: 200, status: 304, body: ''});
  console.log('PASS browser: a separate origin reads ETag and receives an empty 304 after an actual CORS preflight.');
} finally {
  otherOrigin.close();
  await fetch(cdp + '/json/close/' + page.id).catch(() => {});
  ws.close();
}
