// Mutates only the authorized disposable novacart-perf stack.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {randomUUID} from 'node:crypto';
assert.equal(process.env.DISPOSABLE_CONFIRM, 'novacart-perf');
assert.ok(process.env.ADMIN_PASSWORD, 'Set the disposable administrator password.');
const base = 'http://127.0.0.1:18090/api/v1', nonce = randomUUID().replaceAll('-', '');
const docker = (...args) => execFileSync('docker', args, {encoding: 'utf8'}).trim();
const sql = statement => docker('exec', 'novacart-perf_db_1', 'psql', '-U', 'novacart', '-d', 'novacart_perf', '-v', 'ON_ERROR_STOP=1', '-Atc', statement);
async function api(method, path, token, body, expected = 200) {
    const form = body instanceof FormData;
    const response = await fetch(base + path, {method, headers: {Accept: 'application/json', ...(form ? {} : {'Content-Type': 'application/json'}), ...(token ? {Authorization: `Bearer ${token}`} : {})}, body: body === undefined ? undefined : form ? body : JSON.stringify(body), signal: AbortSignal.timeout(15000)});
    const json = await response.json();
    if (expected !== null) assert.equal(response.status, expected, `${method} ${path}: ${response.status} ${json.message || ''}`);
    return {status: response.status, json};
}
const admin = (await api('POST', '/login', null, {email: process.env.ADMIN_EMAIL || 'admin@example.com', password: process.env.ADMIN_PASSWORD})).json.token;
for (const path of ['/login', '/register']) {
    const invalid = await api('POST', path, null, {email: ['invalid@example.test'], username: `invalid${nonce}`, password: 'ValidationOnly123!', password_confirmation: 'ValidationOnly123!'}, 422);
    assert.ok(invalid.json.errors.email);
}
console.log('PASS: malformed email arrays return 422 on login and registration.');
const configuredEmail = `Case${nonce}@Example.test`, username = `case${nonce}`;
const php = `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if (!$app->environment('testing')) exit(1); config(['store.admin.email'=>' ${configuredEmail} ', 'store.admin.username'=>'${username}', 'store.admin.password'=>'ConfiguredOnly123!']); $app->make(Database\\Seeders\\AdminUserSeeder::class)->run();`;
try {
    docker('exec', '--user', 'www-data', 'novacart-perf_app_1', 'php', '-r', php);
    const login = await api('POST', '/login', null, {email: configuredEmail.toUpperCase(), password: 'ConfiguredOnly123!'});
    assert.equal(login.json.data.email, configuredEmail.toLowerCase());
} finally { sql(`DELETE FROM users WHERE username='${username}'`); }
console.log('PASS: mixed-case, padded administrator configuration seeds an account that can sign in.');
async function waitForSleep() {
    for (let index = 0; index < 80; index++) {
        if (Number(sql("SELECT count(*) FROM pg_stat_activity WHERE datname='novacart_perf' AND wait_event='PgSleep'"))) return;
        await new Promise(resolve => setTimeout(resolve, 25));
    }
    throw new Error('Concurrency barrier was not reached');
}
async function allocationRace(name, duplicate = false) {
    try {
        sql(`CREATE FUNCTION verify_category_allocator() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.name='${name}+' THEN PERFORM pg_sleep(1); END IF; RETURN NEW; END; $$; CREATE TRIGGER verify_category_allocator BEFORE INSERT ON categories FOR EACH ROW EXECUTE FUNCTION verify_category_allocator()`);
        const first = api('POST', '/admin/categories', admin, {name: name + '+'}, null);
        await waitForSleep();
        const outcomes = await Promise.all([first, api('POST', '/admin/categories', admin, {name: name + (duplicate ? '+' : '#')}, null)]);
        assert.deepEqual(outcomes.map(x => x.status).sort(), duplicate ? [201, 422] : [201, 201]);
        if (!duplicate) assert.notEqual(outcomes[0].json.data.slug, outcomes[1].json.data.slug);
        else assert.ok(outcomes[1].json.errors.name);
        return outcomes[0].json.data;
    } finally { sql('DROP TRIGGER IF EXISTS verify_category_allocator ON categories; DROP FUNCTION IF EXISTS verify_category_allocator()'); }
}
const category = await allocationRace(`Allocate${nonce}`);
await allocationRace(`Duplicate${nonce}`, true);
const renameA = (await api('POST', '/admin/categories', admin, {name: `RenameA${nonce}`}, 201)).json.data;
const renameB = (await api('POST', '/admin/categories', admin, {name: `RenameB${nonce}`}, 201)).json.data;
try {
    sql(`CREATE FUNCTION verify_category_rename() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id=${Number(renameA.id)} THEN PERFORM pg_sleep(1); END IF; RETURN NEW; END; $$; CREATE TRIGGER verify_category_rename BEFORE UPDATE ON categories FOR EACH ROW EXECUTE FUNCTION verify_category_rename()`);
    const first = api('PUT', `/admin/categories/${renameA.id}`, admin, {name: `RenameTarget${nonce}`}, null);
    await waitForSleep();
    const outcomes = await Promise.all([first, api('PUT', `/admin/categories/${renameB.id}`, admin, {name: `RenameTarget${nonce}`}, null)]);
    assert.deepEqual(outcomes.map(x => x.status).sort(), [200, 422]);
} finally { sql('DROP TRIGGER IF EXISTS verify_category_rename ON categories; DROP FUNCTION IF EXISTS verify_category_rename()'); }
console.log('PASS: concurrent slug collisions allocate distinct slugs; duplicate creates/renames return validation errors.');
const files = () => JSON.parse(docker('exec', 'novacart-perf_app_1', 'php', '-r', "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo json_encode(Illuminate\\Support\\Facades\\Storage::disk('public')->allFiles('products'));"));
const baseline = new Set(files());
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
function productForm(title, update = false) {
    const form = new FormData();
    for (const [key, value] of Object.entries({title, description: 'Disposable image regression', price: '3.00', category_id: category.id, ...(update ? {_method: 'PUT'} : {stock_quantity: 3})})) form.set(key, String(value));
    form.set('image', new Blob([png], {type: 'image/png'}), 'fixture.png');
    return form;
}
for (const deleting of [false, true]) {
    const title = `Image${nonce}${deleting ? 'Delete' : 'Replace'}`;
    const product = (await api('POST', '/admin/products', admin, productForm(title), 201)).json.data;
    try {
        sql(`CREATE FUNCTION verify_image_update() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id=${Number(product.id)} AND NEW.title='${title}First' THEN PERFORM pg_sleep(1); END IF; RETURN NEW; END; $$; CREATE TRIGGER verify_image_update BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION verify_image_update()`);
        const first = api('POST', `/admin/products/${product.id}`, admin, productForm(title + 'First', true), null);
        await waitForSleep();
        const second = deleting ? api('DELETE', `/admin/products/${product.id}`, admin, undefined, null) : api('POST', `/admin/products/${product.id}`, admin, productForm(title + 'Second', true), null);
        const outcomes = await Promise.all([first, second]);
        if (deleting) { assert.ok([200, 404].includes(outcomes[0].status)); assert.equal(outcomes[1].status, 200); }
        else {
            assert.deepEqual(outcomes.map(x => x.status), [200, 200]);
            const latest = (await api('GET', `/products/${product.id}`)).json.data;
            assert.deepEqual(files().filter(path => !baseline.has(path)), [latest.image_path]);
            await api('DELETE', `/admin/products/${product.id}`, admin);
        }
        assert.deepEqual(files().filter(path => !baseline.has(path)), []);
    } finally { sql('DROP TRIGGER IF EXISTS verify_image_update ON products; DROP FUNCTION IF EXISTS verify_image_update()'); }
}
console.log('PASS: concurrent image replacement and hard deletion leave no orphaned fixture files.');
