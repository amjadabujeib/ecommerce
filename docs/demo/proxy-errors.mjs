// Run against an isolated Nginx container with its PHP upstream unavailable.
import assert from 'node:assert/strict';
const base = process.env.BASE_URL || 'http://127.0.0.1:8080';
for (const [status, path, options] of [
    [413, '/api/v1/admin/products', {method: 'POST', body: Buffer.alloc(7 * 1024 * 1024, 120)}],
    [502, '/api/v1/me', {}],
    [403, '/.env', {}],
]) {
    const response = await fetch(base + path, {...options, headers: {Accept: 'application/json'}});
    assert.equal(response.status, status);
    assert.match(response.headers.get('content-type'), /application\/json/);
    const body = await response.json();
    assert.equal(body.status, status);
    assert.equal(typeof body.code, 'string'); assert.equal(typeof body.message, 'string');
    assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
    assert.equal(response.headers.get('x-frame-options'), 'DENY');
    if (status === 502) assert.equal(response.headers.get('retry-after'), '5');
    console.log(`PASS proxy ${status}: ${body.code}`);
}

const asset = await fetch(base + '/assets/shop.css');
assert.equal(asset.status, 200);
assert.equal(asset.headers.get('cache-control'), 'no-cache');
assert.equal(asset.headers.get('x-content-type-options'), 'nosniff');
console.log('PASS assets: stable CSS/JS paths require browser revalidation.');
