// Actual browser/JWT regression; only the disposable novacart-perf stack and CDP.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {randomUUID} from 'node:crypto';
assert.equal(process.env.DISPOSABLE_CONFIRM, 'novacart-perf');
const base = 'http://127.0.0.1:18090', cdp = 'http://127.0.0.1:19223';
const nonce = randomUUID().replaceAll('-', '');
const registered = await fetch(base + '/api/v1/register', {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify({username: `session${nonce}`, email: `session${nonce}@example.test`, password: 'BrowserOnly123!', password_confirmation: 'BrowserOnly123!'})});
assert.equal(registered.status, 201);
const registeredAccount = await registered.json(), account = registeredAccount.data;
const apiHeaders = {Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${registeredAccount.token}`};
assert.equal((await fetch(base + '/api/v1/cart/10001', {method: 'POST', headers: apiHeaders, body: JSON.stringify({quantity: 1})})).status, 200);
const placedResponse = await fetch(base + '/api/v1/orders', {method: 'POST', headers: apiHeaders});
assert.equal(placedResponse.status, 201);
const ownOrder = (await placedResponse.json()).data.id;
const otherResponse = await fetch(base + '/api/v1/register', {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: JSON.stringify({username: `other${nonce}`, email: `other${nonce}@example.test`, password: 'BrowserOnly123!', password_confirmation: 'BrowserOnly123!'})});
assert.equal(otherResponse.status, 201); const otherAccount = (await otherResponse.json()).data;
function expiredToken() {
    const php = `require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();if(!$app->environment('testing'))exit(1);Carbon\\Carbon::setTestNow(Carbon\\Carbon::now()->subMinutes(config('jwt.ttl')+1));echo PHPOpenSourceSaver\\JWTAuth\\Facades\\JWTAuth::fromUser(App\\Modules\\Users\\Models\\User::findOrFail(${Number(account.id)}));`;
    return execFileSync('docker', ['exec', '--user', 'www-data', 'novacart-perf_app_1', 'php', '-r', php], {encoding: 'utf8'}).trim();
}
const firstToken = expiredToken(), secondToken = expiredToken();
const probe = await fetch(base + '/api/v1/me', {headers: {Authorization: `Bearer ${firstToken}`, Accept: 'application/json'}});
assert.equal(probe.status, 401); await probe.arrayBuffer();
const page = await (await fetch(cdp + '/json/new?about:blank', {method: 'PUT'})).json();
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise(resolve => ws.addEventListener('open', resolve, {once: true}));
let sequence = 0, intercepted = 0, holdOrder = false, heldOrder = null; const pending = new Map();
ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id) { const entry = pending.get(message.id); pending.delete(message.id); if (message.error) entry.reject(new Error(message.error.message)); else entry.resolve(message.result); }
    if (message.method === 'Fetch.requestPaused') {
        if (message.params.request.url.includes('/api/v1/orders')) {
            if (holdOrder) { holdOrder = false; heldOrder = message.params.requestId; }
            else send('Fetch.continueRequest', {requestId: message.params.requestId}).catch(() => {});
        } else {
            intercepted++;
            send('Fetch.fulfillRequest', {requestId: message.params.requestId, responseCode: 503, responseHeaders: [{name: 'Content-Type', value: 'application/json'}], body: Buffer.from(JSON.stringify({status: 503, code: 'service_unavailable', message: 'Temporary refresh outage'})).toString('base64')}).catch(() => {});
        }
    }
});
function send(method, params = {}) { return new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); }); }
async function evaluate(expression) { const result = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true}); if (result.exceptionDetails) throw new Error(result.exceptionDetails.text); return result.result.value; }
async function wait(expression) { for (let attempt = 0; attempt < 150; attempt++) { if (await evaluate(expression)) return; await new Promise(resolve => setTimeout(resolve, 100)); } throw new Error('Browser session condition timed out'); }
try {
    await send('Page.enable'); await send('Network.enable'); await send('Network.setCacheDisabled', {cacheDisabled: true});
    await send('Page.navigate', {url: base});
    await wait("document.querySelector('#products .card') && document.querySelector('#loading').hidden");
    const results = await evaluate(`(async () => {
        const {createApiClient} = await import('/assets/api-client.js');
        const expired = ${JSON.stringify(firstToken)};
        let refreshes = 0, arrived = 0, release, saved;
        const gate = new Promise(resolve => { release = resolve; });
        const client = createApiClient({initialToken: expired, onToken: value => { saved = value; }, fetchImpl: async (url, options) => {
            if (url.endsWith('/refresh')) refreshes++;
            const response = await fetch(url, options);
            if (!url.endsWith('/refresh') && response.status === 401 && options.headers.Authorization === 'Bearer ' + expired) { arrived++; if(arrived === 2) release(); await gate; }
            return response;
        }});
        const responses = await Promise.all([client.request('/orders'), client.request('/notifications')]);
        return {refreshes, successes:responses.length, rotated:!!saved && saved!==expired};
    })()`);
    assert.deepEqual(results, {refreshes: 1, successes: 2, rotated: true});
    console.log('PASS browser: two real expired-JWT requests share one refresh and both complete.');
    await send('Fetch.enable', {patterns: [{urlPattern: '*api/v1/refresh', requestStage: 'Request'}]});
    await evaluate(`sessionStorage.setItem('novacart-token', ${JSON.stringify(secondToken)}); location.reload()`);
    await wait("document.querySelector('#notice-text')?.textContent.includes('Temporary refresh outage') && document.querySelector('#loading').hidden");
    assert.equal(await evaluate(`sessionStorage.getItem('novacart-token') === ${JSON.stringify(secondToken)}`), true);
    assert.equal(intercepted, 1);
    console.log('PASS browser: a temporary refresh outage during GUI startup retains the stored session.');
    await send('Fetch.disable'); await send('Page.reload');
    await wait(`document.querySelector('#identity')?.textContent.includes(${JSON.stringify(account.username)}) && document.querySelector('#loading').hidden`);
    assert.equal(await evaluate(`!!sessionStorage.getItem('novacart-token') && sessionStorage.getItem('novacart-token') !== ${JSON.stringify(secondToken)}`), true);
    console.log('PASS browser: reloading after recovery restores the same account without signing in again.');
    holdOrder = true;
    await send('Fetch.enable', {patterns: [{urlPattern: '*api/v1/orders*', requestStage: 'Response'}]});
    await evaluate("location.hash = '#/orders'");
    for (let i = 0; i < 100 && !heldOrder; i++) await new Promise(resolve => setTimeout(resolve, 50));
    assert.ok(heldOrder, 'The first account order response must be held');
    await evaluate("document.querySelector('#logout').click()");
    await wait("!document.querySelector('#login').hidden && document.querySelector('#loading').hidden");
    await evaluate(`(() => { const form = document.querySelector('#login'); form.elements.email.value = ${JSON.stringify(otherAccount.email)}; form.elements.password.value = 'BrowserOnly123!'; form.requestSubmit(form.querySelector('button')); })()`);
    await wait(`document.querySelector('#identity').textContent.includes(${JSON.stringify(otherAccount.username)}) && document.querySelector('#loading').hidden`);
    await evaluate("location.hash = '#/orders'");
    await wait("document.querySelector('#order-list').textContent.includes('No orders yet') && document.querySelector('#loading').hidden");
    await send('Fetch.continueRequest', {requestId: heldOrder}); heldOrder = null;
    await new Promise(resolve => setTimeout(resolve, 150));
    assert.equal(await evaluate(`document.querySelector('#order-list').textContent.includes('Order #${ownOrder}')`), false);
    assert.equal(await evaluate("document.querySelector('#order-list').textContent.includes('No orders yet')"), true);
    console.log('PASS browser: a delayed previous-account order response cannot overwrite the new account view.');
} finally {
    if (heldOrder) await send('Fetch.continueRequest', {requestId: heldOrder}).catch(() => {});
    await send('Fetch.disable').catch(() => {});
    await fetch(cdp + '/json/close/' + page.id).catch(() => {}); ws.close();
}
