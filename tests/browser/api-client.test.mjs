import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source = await readFile(new URL('../../laravel-app/public/assets/api-client.js', import.meta.url), 'utf8');
const {createApiClient} = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const response = (status, data) => ({status, ok: status >= 200 && status < 300, json: async () => data});

test('overlapping 401s share one refresh and retain a valid session', async () => {
    let refreshes = 0, ended = 0, saved;
    const client = createApiClient({initialToken: 'old', onToken: value => { saved = value; }, onSessionEnded: () => ended++, fetchImpl: async (url, options) => {
        if (url.endsWith('/refresh')) { refreshes++; await sleep(10); return refreshes === 1 ? response(200, {token: 'fresh'}) : response(401, {}); }
        return options.headers.Authorization === 'Bearer fresh' ? response(200, {data: true}) : response(401, {});
    }});
    const results = await Promise.all([client.request('/orders'), client.request('/notifications')]);
    assert.equal(results.length, 2); assert.equal(refreshes, 1); assert.equal(saved, 'fresh'); assert.equal(ended, 0);
});

test('late old-token failures retry the rotated token without rotating again', async () => {
    let refreshes = 0;
    const client = createApiClient({initialToken: 'old', fetchImpl: async (url, options) => {
        if (url.endsWith('/refresh')) { refreshes++; return response(200, {token: 'fresh'}); }
        if (options.headers.Authorization === 'Bearer fresh') return response(200, {});
        if (url.endsWith('/slow')) await sleep(20);
        return response(401, {});
    }});
    await Promise.all([client.request('/fast'), client.request('/slow')]);
    assert.equal(refreshes, 1);
});

test('a refresh cannot overwrite a new login or replay another account\'s mutation', async () => {
    let start, complete, saved, mutations = 0;
    const started = new Promise(resolve => { start = resolve; });
    const client = createApiClient({initialToken: 'old', onToken: value => { saved = value; }, fetchImpl: async (url, options) => {
        if (url.endsWith('/refresh')) { start(); return new Promise(resolve => { complete = () => resolve(response(200, {token: 'old-account-fresh'})); }); }
        mutations++; return response(401, {});
    }});
    const pending = client.request('/cart/1', {method: 'POST', body: {quantity: 1}});
    const checked = assert.rejects(pending, /session changed/i);
    await started; client.setToken('new-account'); complete(); await checked;
    assert.equal(saved, 'new-account'); assert.equal(mutations, 1);
});

test('logout during refresh cannot resurrect the previous token', async () => {
    let start, complete, saved;
    const started = new Promise(resolve => { start = resolve; });
    const client = createApiClient({initialToken: 'old', onToken: value => { saved = value; }, fetchImpl: async url => {
        if (url.endsWith('/refresh')) { start(); return new Promise(resolve => { complete = () => resolve(response(200, {token: 'fresh'})); }); }
        return response(401, {});
    }});
    const pending = client.request('/orders'); const checked = assert.rejects(pending, /session changed/i);
    await started; client.setToken(null); complete(); await checked; assert.equal(saved, null);
});

test('invalid refresh clears the session once; temporary outages preserve it', async () => {
    for (const status of [401, 503]) {
        let saved = 'old', ended = 0;
        const client = createApiClient({initialToken: 'old', onToken: value => { saved = value; }, onSessionEnded: () => ended++, fetchImpl: async url => {
            if (url.endsWith('/refresh')) { await sleep(10); return response(status, {message: 'Refresh unavailable'}); }
            return response(401, {});
        }});
        await Promise.allSettled([client.request('/orders'), client.request('/notifications')]);
        assert.equal(saved, status === 401 ? null : 'old'); assert.equal(ended, status === 401 ? 1 : 0);
    }
});

test('late successful private responses cannot cross a logout or account change', async () => {
    let complete;
    const client = createApiClient({initialToken: 'account-a', fetchImpl: () => new Promise(resolve => { complete = () => resolve(response(200, {data: 'private-a'})); })});
    const pending = client.request('/orders');
    const checked = assert.rejects(pending, /session changed/i);
    client.setToken('account-b'); complete(); await checked;
});
