// Starts disposable services from cached images only; always removes its own containers.
// Run only when authorized: DISPOSABLE_CONFIRM=cache-routing node docs/performance/verify-cache-routing.mjs
import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {resolve} from 'node:path';

assert.equal(process.env.DISPOSABLE_CONFIRM, 'cache-routing', 'Explicitly authorize this isolated service test.');
const root = fileURLToPath(new URL('../../', import.meta.url));
process.chdir(root);
const p = (...args) => execFileSync('podman', args, {encoding: 'utf8', maxBuffer: 200000}).trim();
const prefix = 'novacart-routing-' + process.pid;
const names = {network: prefix, db: prefix+'-db', redis: prefix+'-redis', app: prefix+'-app', replica: prefix+'-replica', inventory: prefix+'-inventory'};
for (const image of ['localhost/novacart-core:local', 'localhost/novacart-perf_inventory:latest', 'docker.io/library/postgres:17-alpine', 'docker.io/library/redis@sha256:e4ee3e03ce8f75471f40937a7c6b2b0e500390b6812fcdf5cb32c01295ded446']) {
    p('image', 'exists', image); // No pulls, builds, dependency installation, or existing volumes.
}
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function ready(container, command) {
    for (let attempt=0; attempt<100; attempt++) {
        try { p('exec', container, ...command); return; } catch { await delay(100); }
    }
    throw new Error('Disposable service did not become ready: '+container);
}
try {
p('network','create','--internal',names.network);
p('run','-d','--pull=never','--name',names.db,'--network',names.network,'--network-alias','db','--tmpfs','/var/lib/postgresql/data:rw','-e','POSTGRES_DB=novacart_routing_perf','-e','POSTGRES_USER=novacart','-e','POSTGRES_PASSWORD=disposable-test-only','docker.io/library/postgres:17-alpine','postgres','-c','wal_level=replica','-c','max_wal_senders=10');
p('run','-d','--pull=never','--name',names.redis,'--network',names.network,'--network-alias','redis','docker.io/library/redis@sha256:e4ee3e03ce8f75471f40937a7c6b2b0e500390b6812fcdf5cb32c01295ded446','redis-server','--save','','--appendonly','no');
await ready(names.db, ['pg_isready','-h','127.0.0.1','-U','novacart']);
const sql=`CREATE ROLE novacart_reader LOGIN PASSWORD 'disposable-reader'; ALTER ROLE novacart_reader SET default_transaction_read_only=on; GRANT CONNECT ON DATABASE novacart_routing_perf TO novacart_reader; GRANT USAGE ON SCHEMA public TO novacart_reader; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO novacart_reader; CREATE ROLE replicator LOGIN REPLICATION PASSWORD 'disposable-replication'; CREATE DATABASE inventory;`;
for(const stmt of sql.split(';').filter(x=>x.trim()))p('exec',names.db,'psql','-U','novacart','-d','novacart_routing_perf','-v','ON_ERROR_STOP=1','-c',stmt);
p('exec',names.db,'sh','-c',"printf '\nhost replication replicator all scram-sha-256\n' >> /var/lib/postgresql/data/pg_hba.conf");
p('exec',names.db,'psql','-U','novacart','-d','novacart_routing_perf','-c','SELECT pg_reload_conf()');
p('run','-d','--pull=never','--name',names.inventory,'--network',names.network,'--network-alias','inventory','-e','DATABASE_URL=postgres://novacart:disposable-test-only@db:5432/inventory','localhost/novacart-perf_inventory:latest');
const env={APP_ENV:'testing',APP_DEBUG:'false',APP_KEY:'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',DB_URL:'',REDIS_CATALOG_URL:'',CATALOG_CACHE_STORE:'catalog',REDIS_CATALOG_DB:'2',DB_CONNECTION:'pgsql',DB_HOST:'db',DB_PORT:'5432',DB_DATABASE:'novacart_routing_perf',DB_USERNAME:'novacart',DB_PASSWORD:'disposable-test-only',DB_READ_HOST:'db-replica',DB_READ_PASSWORD:'disposable-reader',READ_REPLICA_ENABLED:'true',CATALOG_CACHE_ENABLED:'true',PERFORMANCE_ENABLED:'true',PERFORMANCE_DIAGNOSTICS:'true',PERFORMANCE_LOAD_TESTING:'true',REDIS_CLIENT:'predis',REDIS_HOST:'redis',CACHE_STORE:'array',SESSION_DRIVER:'array',QUEUE_CONNECTION:'sync',JWT_SECRET:'disposable-testing-secret-0123456789abcdef',INVENTORY_GRPC_ADDRESS:'inventory:50051',LOG_CHANNEL:'stderr',PHP_CLI_SERVER_WORKERS:'4'};
p('run','-d','--pull=never','--name',names.app,'--network',names.network,'-p','127.0.0.1::8000','-v',process.cwd()+'/laravel-app:/var/www/html:Z','-v',process.cwd()+'/docs/performance:/checks:Z','--tmpfs','/var/www/html/bootstrap/cache:rw,mode=1777','--tmpfs','/var/www/html/storage:rw,mode=1777',...Object.entries(env).flatMap(([k,v])=>['-e',k+'='+v]),'--entrypoint','sh','localhost/novacart-core:local','-c','mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs; exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload');
p('exec',names.app,'php','artisan','migrate','--force','--no-interaction');
p('run','-d','--pull=never','--name',names.replica,'--network',names.network,'--network-alias','db-replica','--tmpfs','/var/lib/postgresql/data:rw','-e','PGPASSWORD=disposable-replication','--entrypoint','sh','docker.io/library/postgres:17-alpine','-c','chown postgres:postgres /var/lib/postgresql/data; chmod 700 /var/lib/postgresql/data; gosu postgres pg_basebackup -h db -U replicator -D /var/lib/postgresql/data -Fp -X stream -R -c fast; exec gosu postgres postgres -c hot_standby=on');
names.port=p('port',names.app,'8000/tcp').split(':').at(-1);

    await ready(names.replica, ['pg_isready', '-h', '127.0.0.1', '-U', 'novacart']);
    p('exec', names.app, 'php', '/checks/cache-routing-check.php', 'fixture');
    await verifyApi(names);
    await verifyRaces(names);
    await verifyRecovery(names);
    await verifyOrders(names);
    await verifyReviewRegressions(names);
    await verifyMaintenanceRegressions(names);
    await verifyLostFillLock(names);
    if (process.env.RUN_K6 === 'true') await benchmark(names);
    console.log('PASS: isolated PostgreSQL/Redis/gRPC cache and routing integration.');
} finally {
    for (const name of [names.app, names.inventory, names.replica, names.redis, names.db]) {
        try { p('rm', '-f', '-t', '1', name); } catch {}
    }
    try { p('network', 'rm', names.network); } catch {}
}

async function verifyApi(s) {
const p=(...a)=>execFileSync('podman',a,{encoding:'utf8',maxBuffer:200000}).trim();
const sql=(statement,replica=false)=>p('exec',replica?s.replica:s.db,'psql','-U','novacart','-d','novacart_routing_perf','-At','-v','ON_ERROR_STOP=1','-c',statement);
const updatedTitle='Committed API update '+Date.now();
const base=`http://127.0.0.1:${s.port}/api/v1`;
async function request(method,path,token,body,expected=200,extra={}){
 const response=await fetch(base+path,{method,headers:{Accept:'application/json','Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`} :{}),...extra},body:body===undefined?undefined:JSON.stringify(body),signal:AbortSignal.timeout(10000)});
 const text=await response.text();assert.equal(response.status,expected,`${method} ${path}: ${text.slice(0,1500)}`);return {response,json:text?JSON.parse(text):null};
}
console.log(p('exec',s.app,'php','/checks/cache-routing-check.php','routing'));
const admin=(await request('POST','/login',null,{email:'routing-admin@example.test',password:'DisposableTest123!'})).json.token;
const customer=(await request('POST','/login',null,{email:'routing-customer@example.test',password:'DisposableTest123!'})).json.token;
p('exec',s.redis,'redis-cli','-n','1','SET','unrelated-proof','keep-me');
let result=await request('GET','/?page=1');console.log('list',result.response.headers.get('x-catalog-cache'),result.response.headers.get('x-read-database'));assert.equal(result.response.headers.get('x-catalog-cache'),'miss');
result=await request('GET','/?page=1');assert.equal(result.response.headers.get('x-catalog-cache'),'hit');
let detail=await request('GET','/products/1'); const tag=detail.response.headers.get('etag');
await request('GET','/products/1',null,undefined,304,{'If-None-Match':tag});
let keys=p('exec',s.redis,'redis-cli','-n','2','--scan');assert.match(keys,/catalog:list:/);assert.doesNotMatch(keys,/catalog:v\d/);
const oldKeys=keys.split('\n');
await request('PUT','/admin/products/1',admin,{title:updatedTitle,description:'Disposable',price:12,category_id:1});
assert.equal(p('exec',s.redis,'redis-cli','-n','2','DBSIZE'),'0','Commit must physically clear catalog DB');
assert.equal(p('exec',s.redis,'redis-cli','-n','1','GET','unrelated-proof'),'keep-me');
assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'),'f');
console.log('PASS: cache hits, stable keys, 304, physical deletion and isolation.');
const title=(await request('GET','/products/1')).json.data.title;assert.equal(title,updatedTitle);
sql('SELECT pg_wal_replay_pause()',true);
try {
 await request('PUT','/admin/products/1',admin,{title:'Visible during lag',description:'Disposable',price:14,category_id:1});
 const fence=sql('SELECT read_fence FROM users WHERE id=1');assert.ok(fence);
 console.log(p('exec',s.app,'php','/checks/cache-routing-check.php','fenced-read',fence));
 result=await request('GET','/products/1');assert.equal(result.json.data.title,'Visible during lag');assert.equal(result.response.headers.get('x-read-database'),'primary');
 result=await request('GET','/admin/orders?page=1',admin);assert.equal(result.response.headers.get('x-read-database'),'primary');
 await request('POST','/cart/1',customer,{quantity:1});
 result=await request('GET','/cart',customer);assert.equal(Number(result.json.data.items[0].product.price),14);
 result=await request('GET','/checkout',customer);assert.equal(Number(result.json.data.items[0].product.price),14);
 const placed=await request('POST','/orders',customer,undefined,201);assert.ok(placed.json.data.id);
 result=await request('GET','/orders?page=1',customer);assert.ok(result.json.data.some(o=>o.id===placed.json.data.id));
 console.log('PASS: WAL fences, lagged cache fills, current cart/checkout and persisted order via real gRPC.');
}finally{sql('SELECT pg_wal_replay_resume()',true);}
for(let i=0;i<50;i++){result=await request('GET','/orders?page=1',customer);if(result.response.headers.get('x-read-database')==='replica')break;await new Promise(r=>setTimeout(r,100));}
assert.equal(result.response.headers.get('x-read-database'),'replica');
console.log('PASS: request routing returns to replica after catch-up.');

}

async function verifyRaces(s) {
const p=(...a)=>execFileSync('podman',a,{encoding:'utf8',maxBuffer:5000}).trim();
const sql=(statement)=>p('exec',s.db,'psql','-U','novacart','-d','novacart_routing_perf','-At','-v','ON_ERROR_STOP=1','-c',statement);
const redis=(...a)=>p('exec',s.redis,'redis-cli','-n','2',...a);
const base=`http://127.0.0.1:${s.port}/api/v1`;
const detail=async()=>{const r=await fetch(base+'/products/1');const b=await r.json();assert.equal(r.status,200,JSON.stringify(b));return {r,b};};
const delay=ms=>new Promise(r=>setTimeout(r,ms));
function child(args){let stdout='',stderr='';const proc=spawn('podman',args);proc.stdout.on('data',d=>stdout+=d);proc.stderr.on('data',d=>stderr+=d);return {proc,done:new Promise((resolve,reject)=>proc.on('exit',code=>code===0?resolve(stdout):reject(new Error(stderr+stdout))))};}
sql("UPDATE products SET title='Before fill' WHERE id=1");
p('exec',s.app,'rm','-f','/tmp/catalog-fill-ready','/tmp/catalog-fill-release');
const fill=child(['exec',s.app,'php','/checks/cache-routing-check.php','fill-race','1']);
let ready=false;for(let i=0;i<100;i++){try{p('exec',s.app,'test','-f','/tmp/catalog-fill-ready');ready=true;break}catch{}await delay(50);}
assert.ok(ready,'fill must reach barrier');
const update=child(['exec',s.db,'psql','-U','novacart','-d','novacart_routing_perf','-v','ON_ERROR_STOP=1','-c',"UPDATE products SET title='After fill' WHERE id=1"]);
let blocked=false;for(let i=0;i<50;i++){if(Number(sql("SELECT count(*) FROM pg_stat_activity WHERE wait_event_type='Lock' AND query LIKE '%After fill%'"))>0){blocked=true;break;}await delay(50);}
assert.ok(blocked,'writer must wait for in-flight cache fill');
p('exec',s.app,'touch','/tmp/catalog-fill-release');
assert.equal(JSON.parse(await fill.done).title,'Before fill');await update.done;
assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'),'t');
let result=await detail();assert.equal(result.b.data.title,'After fill');
assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'),'f');
assert.doesNotMatch(redis('--scan'),/catalog:race:/,'old fill must be physically deleted');
console.log('PASS: deterministic late fill cannot resurrect stale cache after external SQL write.');
// Capture old entries, simulate Redis hanging, then write directly to PostgreSQL.
await detail();const before=redis('--scan');assert.ok(before);
p('exec',s.redis,'redis-cli','CLIENT','PAUSE','3000','ALL');
sql("UPDATE products SET title='Changed during Redis stall' WHERE id=1");
const start=Date.now();result=await detail();const elapsed=Date.now()-start;
assert.equal(result.b.data.title,'Changed during Redis stall');assert.equal(result.r.headers.get('x-catalog-cache'),'unavailable');assert.ok(elapsed<2500,`stall lasted ${elapsed}ms`);
assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'),'t');
await delay(3100);
result=await detail();assert.equal(result.b.data.title,'Changed during Redis stall');assert.equal(result.r.headers.get('x-catalog-cache'),'miss');
assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'),'f');
console.log('PASS: bounded Redis stall bypass, durable dirty flag and automatic recovery.');
// An external rolled-back write must leave a clean cache intact.
await detail();sql("BEGIN; UPDATE products SET title='Rolled back title' WHERE id=1; ROLLBACK;");
assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'),'f');
result=await detail();assert.equal(result.r.headers.get('x-catalog-cache'),'hit');assert.equal(result.b.data.title,'Changed during Redis stall');
console.log('PASS: rolled-back catalog write preserves valid cache.');

}

async function verifyRecovery(s) {
    const sql = (statement, replica=false) => p('exec', replica?s.replica:s.db, 'psql', '-U', 'novacart', '-d', 'novacart_routing_perf', '-At', '-v', 'ON_ERROR_STOP=1', '-c', statement);
    const detail = async () => {
        const response = await fetch(`http://127.0.0.1:${s.port}/api/v1/products/1`, {signal: AbortSignal.timeout(10000)});
        const body = await response.json();
        assert.equal(response.status, 200, JSON.stringify(body));
        return {response, body};
    };
    console.log(p('exec', s.app, 'php', '/checks/cache-routing-check.php', 'http-operations'));
    sql("UPDATE products SET title='Crash recovery' WHERE id=1");
    let interrupted = false;
    try { p('exec', s.app, 'php', '/checks/cache-routing-check.php', 'crash-invalidation'); }
    catch (error) { assert.equal(error.status, 31); interrupted = true; }
    assert.ok(interrupted);
    assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'), 't');
    assert.equal((await detail()).body.data.title, 'Crash recovery');
    assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'), 'f');
    console.log('PASS: process interruption leaves recoverable invalidation.');

    p('stop', '-t', '1', s.redis);
    try {
        sql("UPDATE products SET title='Redis refusal recovery' WHERE id=1");
        const result = await detail();
        assert.equal(result.body.data.title, 'Redis refusal recovery');
        assert.equal(result.response.headers.get('x-catalog-cache'), 'unavailable');
        assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'), 't');
    } finally { p('start', s.redis); }
    await ready(s.redis, ['redis-cli', 'ping']);
    assert.equal((await detail()).body.data.title, 'Redis refusal recovery');
    assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'), 'f');
    console.log('PASS: Redis connection refusal and recovery.');

    sql('SELECT pg_wal_replay_pause()', true);
    try {
        sql("UPDATE products SET title='Uncached during lag' WHERE id=1");
        console.log(p('exec', s.app, 'php', '/checks/cache-routing-check.php', 'uncached-fresh'));
        // Authentication must see current roles even when the replica has the old role.
        sql("UPDATE users SET role='customer' WHERE id=1");
        const login = await fetch(`http://127.0.0.1:${s.port}/api/v1/login`, {method:'POST', headers:{'Content-Type':'application/json', Accept:'application/json'}, body:JSON.stringify({email:'routing-admin@example.test',password:'DisposableTest123!'})});
        assert.equal(login.status, 200);
        const token = (await login.json()).token;
        const denied = await fetch(`http://127.0.0.1:${s.port}/api/v1/admin/orders`, {headers:{Authorization:`Bearer ${token}`,Accept:'application/json'}});
        assert.equal(denied.status, 403, 'Lagged roles must not authorize a demoted admin.');
        sql("UPDATE users SET role='admin' WHERE id=1");
    } finally { sql('SELECT pg_wal_replay_resume()', true); }
    p('stop', '-t', '1', s.replica);
    try { console.log(p('exec', s.app, 'php', '/checks/cache-routing-check.php', 'replica-outage')); }
    finally { p('start', s.replica); }
    await ready(s.replica, ['pg_isready', '-h', '127.0.0.1', '-U', 'novacart']);
    console.log('PASS: primary authentication rejects stale replica privileges.');

    p('exec', s.app, 'php', '/checks/cache-routing-check.php', 'drain');
    // Reproduce a table lock preceding a trigger's advisory lock on another PDO.
    const writer = spawn('podman', ['exec', s.db, 'psql', '-U', 'novacart', '-d', 'novacart_routing_perf', '-v', 'ON_ERROR_STOP=1', '-c', "BEGIN; LOCK TABLE products IN ACCESS EXCLUSIVE MODE; SELECT pg_sleep(2); UPDATE products SET title='After exclusive table lock' WHERE id=1; COMMIT;"]);
    let writerError = '';
    writer.stderr.on('data', chunk => writerError += chunk);
    const written = new Promise((resolve, reject) => writer.on('exit', code => code === 0 ? resolve() : reject(new Error(writerError))));
    let sleeping = false;
    for (let attempt=0; attempt<50; attempt++) {
        if (Number(sql("SELECT count(*) FROM pg_stat_activity WHERE wait_event='PgSleep' AND query LIKE '%After exclusive table lock%'")) > 0) { sleeping = true; break; }
        await delay(20);
    }
    assert.ok(sleeping);
    const started = Date.now();
    // Force primary data reads so the table lock is encountered by the fill.
    console.log(p('exec', '-e', 'READ_REPLICA_ENABLED=false', s.app, 'php', '/checks/cache-routing-check.php', 'lock-contention'));
    await written;
    assert.ok(Date.now()-started < 5000, 'Table/advisory contention must not deadlock.');

    // Later milestones add migrations after catalog invalidation. Roll back through
    // this exact migration rather than assuming it remains the newest migration.
    const steps = Number(sql("SELECT count(*) FROM migrations WHERE migration >= '2026_11_17_000001_replace_catalog_versions_with_invalidation'"));
    assert.ok(steps >= 1);
    p('exec', s.app, 'php', 'artisan', 'migrate:rollback', '--step='+steps, '--force', '--no-interaction');
    assert.ok(Number(sql('SELECT version FROM catalog_versions')) > 1);
    p('exec', s.app, 'php', 'artisan', 'migrate', '--force', '--no-interaction');
    assert.equal(sql("SELECT count(*) FROM information_schema.tables WHERE table_name='catalog_versions'"), '0');
    assert.equal((await detail()).body.data.title, 'After exclusive table lock');
    console.log('PASS: migration rollback restores old triggers; reapply restores deletion.');
}

async function benchmark(s) {
    // Separate from correctness fixtures. Only this harness's disposable DB is reset.
    const sql = statement => p('exec', s.db, 'psql', '-U', 'novacart', '-d', 'novacart_routing_perf', '-At', '-v', 'ON_ERROR_STOP=1', '-c', statement);
    sql('TRUNCATE users, categories, products, orders, order_items RESTART IDENTITY CASCADE');
    console.log(p('exec', s.app, 'php', '/checks/seed.php', '200000'));
    console.log(p('exec', s.app, 'php', '/checks/cache-routing-check.php', 'benchmark-admin'));
    p('exec', s.app, 'php', 'artisan', 'config:cache', '--no-interaction');
    const outputDir = resolve(process.env.RESULTS_DIR || '/tmp/novacart-routing-k6');
    const indexes = [
        'CREATE INDEX order_items_order_id_index ON order_items(order_id)',
        'CREATE INDEX orders_created_id_index ON orders(created_at,id)',
        'CREATE INDEX orders_user_created_id_index ON orders(user_id,created_at,id)',
    ];
    // All stages use this checkout, identical fixtures, HTTP server and settings.
    for (const stage of ['baseline', 'indexes-control', 'replica-only', 'replica-cache']) {
        if (stage === 'baseline') {
            for (const name of ['order_items_order_id_index','orders_created_id_index','orders_user_created_id_index']) sql('DROP INDEX '+name);
        } else if (stage === 'indexes-control') {
            for (const statement of indexes) sql(statement);
        }
        p('exec', s.app, 'php', '/checks/cache-routing-check.php', 'benchmark-stage', stage);
        // CLI server recompiles each request; there is no FPM/opcache reload here.
        const args = [fileURLToPath(new URL('./run-k6.mjs', import.meta.url))];
        const environment = {...process.env, DISPOSABLE_CONFIRM: 'cache-routing', BASE_URL: `http://127.0.0.1:${s.port}/api/v1`, ADMIN_PASSWORD: 'DisposableTest123!', STAGE: 'routing-'+stage, OUTPUT: resolve(outputDir, stage+'.json'), CONCURRENCY: '4', WARMUP_SECONDS: '3', DURATION_SECONDS: '20'};
        console.log('Measuring '+stage+' with 200,000 orders / 600,000 lines.');
        await new Promise((resolve, reject) => {
            const run = spawn(process.execPath, args, {env: environment, stdio: 'inherit'});
            run.on('error', reject);
            run.on('exit', code => code === 0 ? resolve() : reject(new Error('k6 stage failed: '+stage)));
        });
    }
    console.log('k6 reports: '+outputDir);
}

async function verifyOrders(s) {
    const base = `http://127.0.0.1:${s.port}/api/v1`;
    const sql = statement => p('exec', s.db, 'psql', '-U', 'novacart', '-d', 'novacart_routing_perf', '-At', '-v', 'ON_ERROR_STOP=1', '-c', statement);
    async function request(method, path, token, body, expected=200) {
        const response = await fetch(base+path, {method, headers:{Accept:'application/json','Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})}, body:body===undefined?undefined:JSON.stringify(body), signal:AbortSignal.timeout(12000)});
        const json = await response.json();
        if (expected!==null) assert.equal(response.status, expected, `${method} ${path}: ${JSON.stringify(json)}`);
        return {response,json};
    }
    const admin = (await request('POST','/login',null,{email:'routing-admin@example.test',password:'DisposableTest123!'})).json.token;
    async function buyer(suffix) {
        const result = await request('POST','/register',null,{username:'buyer '+suffix.replaceAll('-', ' '),email:`buyer-${suffix}@example.test`,password:'DisposableTest123!',password_confirmation:'DisposableTest123!'},201);
        return {token:result.json.token,id:result.json.data.id};
    }
    const buyers = await Promise.all([buyer('race-a'),buyer('race-b')]);
    await request('PATCH','/admin/products/1/stock',admin,{stock_quantity:1});
    await Promise.all(buyers.map(b=>request('POST','/cart/1',b.token,{quantity:1})));
    const outcomes = await Promise.all(buyers.map(b=>request('POST','/orders',b.token,undefined,null)));
    assert.deepEqual(outcomes.map(o=>o.response.status).sort(),[201,409]);
    assert.equal(sql(`SELECT count(*) FROM orders WHERE user_id IN (${buyers.map(b=>Number(b.id)).join(',')})`),'1');
    assert.equal((await request('GET','/products/1')).json.data.stock_quantity,0);
    console.log('PASS: real last-unit race: one 201, one 409, one persisted order, final stock zero.');

    const failing = await buyer('persistence');
    await request('PATCH','/admin/products/1/stock',admin,{stock_quantity:1});
    await request('POST','/cart/1',failing.token,{quantity:1});
    sql(`CREATE FUNCTION reject_routing_order() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.user_id=${Number(failing.id)} THEN RAISE EXCEPTION 'disposable persistence failure'; END IF; RETURN NEW; END; $$; CREATE TRIGGER reject_routing_order BEFORE INSERT ON orders FOR EACH ROW EXECUTE FUNCTION reject_routing_order()`);
    try { await request('POST','/orders',failing.token,undefined,500); }
    finally { sql('DROP TRIGGER reject_routing_order ON orders; DROP FUNCTION reject_routing_order()'); }
    assert.equal(sql(`SELECT count(*) FROM orders WHERE user_id=${Number(failing.id)}`),'0');
    assert.equal((await request('GET','/products/1')).json.data.stock_quantity,1);
    console.log('PASS: persistence failure rolls back order and releases the real inventory reservation.');

    p('stop','-t','1',s.inventory);
    try {
        await request('POST','/orders',failing.token,undefined,503);
        assert.equal(sql(`SELECT count(*) FROM orders WHERE user_id=${Number(failing.id)}`),'0');
    } finally { p('start',s.inventory); }
    let restored = false;
    for (let attempt=0; attempt<50; attempt++) {
        const result = await request('GET','/products/1');
        if (result.json.data.stock_quantity===1) { restored=true; break; }
        await delay(100);
    }
    assert.ok(restored,'Inventory must return with unchanged stock after outage.');
    console.log('PASS: inventory outage rejects checkout with 503 and preserves stock/orders.');
}

async function verifyReviewRegressions(s) {
    const sql = (statement, replica=false) => p('exec', replica?s.replica:s.db, 'psql', '-U', 'novacart', '-d', 'novacart_routing_perf', '-At', '-v', 'ON_ERROR_STOP=1', '-c', statement);
    function request(store, method, path, body, token, expected=200) {
        const output = execFileSync('podman', ['exec','-i','-e',`CACHE_STORE=${store}`,s.app,'php','/checks/cache-routing-check.php','review-request'], {
            input:JSON.stringify({method,path:'/api/v1'+path,body,token}), encoding:'utf8', maxBuffer:200000,
        });
        const result=JSON.parse(output);
        assert.equal(result.status,expected,`${store} ${method} ${path}: HTTP ${result.status}`);
        return result.body;
    }
    // Wait until all existing fixtures have reached the replica before freezing it.
    const fence=sql('SELECT pg_current_wal_lsn()');
    let caught=false;
    for(let i=0;i<100;i++) {
        if(sql(`SELECT pg_last_wal_replay_lsn() >= '${fence}'::pg_lsn`,true)==='t'){caught=true;break;}
        await delay(50);
    }
    assert.ok(caught);
    sql('SELECT pg_wal_replay_pause()',true);
    try {
        for(const store of ['array','database']) {
            const admin=request(store,'POST','/login',{email:'routing-admin@example.test',password:'DisposableTest123!'}).token;
            const email=`review-${store}@example.test`;
            const user=request(store,'POST','/register',{username:'review '+store,email,password:'OriginalReview123!',password_confirmation:'OriginalReview123!'},undefined,201);
            const signed=request(store,'POST','/login',{email,password:'OriginalReview123!'});
            assert.ok(signed.token);
            p('exec',s.app,'php','/checks/cache-routing-check.php','review-password',email);
            request(store,'POST','/login',{email,password:'OriginalReview123!'},undefined,422);
            const changed=request(store,'POST','/login',{email,password:'ChangedReview123!'});
            assert.ok(changed.token);
            const orderId=Number(sql(`INSERT INTO orders (user_id,total_amount,status,created_at,updated_at) VALUES (${Number(user.data.id)},10,'pending',now(),now()) RETURNING id`).split('\n')[0]);
            sql(`INSERT INTO order_items (order_id,product_id,quantity,unit_price,created_at,updated_at) VALUES (${orderId},1,1,10,now(),now())`);
            assert.equal(sql(`SELECT count(*) FROM orders WHERE id=${orderId}`,true),'0');
            const order=request(store,'GET',`/orders/${orderId}`,undefined,admin).data;
            assert.equal(order.items.length,1,'Primary-bound order must include its unreplicated lines');
            assert.equal(Number(order.total_amount),10);
            if(store==='database') {
                request(store,'POST','/logout',undefined,changed.token);
                request(store,'GET','/me',undefined,changed.token,401);
                request(store,'POST','/refresh',undefined,changed.token,401);
                const fresh=request(store,'POST','/login',{email,password:'ChangedReview123!'}).token;
                const rotated=request(store,'POST','/refresh',undefined,fresh).token;
                request(store,'GET','/me',undefined,fresh,401);
                request(store,'GET','/me',undefined,rotated);
            }
            console.log(`PASS: ${store} cache, paused replay: immediate login/password changes and complete cross-user order details.`);
        }
        console.log('PASS: database JWT blacklist rejects logged-out and rotated tokens across processes during replica lag.');
    } finally {sql('SELECT pg_wal_replay_resume()',true);}
}

async function verifyMaintenanceRegressions(s) {
const sql=(q,r=false)=>p('exec',r?s.replica:s.db,'psql','-U','novacart','-d','novacart_routing_perf','-At','-v','ON_ERROR_STOP=1','-c',q);
const request=(method,path,token,body)=>JSON.parse(execFileSync('podman',['exec','-i','-e','CACHE_STORE=array',s.app,'php','/checks/cache-routing-check.php','review-request'],{input:JSON.stringify({method,path:'/api/v1'+path,token,body}),encoding:'utf8'}));
const fence=sql('SELECT pg_current_wal_lsn()');
for(let i=0;i<100;i++){if(sql(`SELECT pg_last_wal_replay_lsn() >= '${fence}'::pg_lsn`,true)==='t')break;await delay(50);}
sql('SELECT pg_wal_replay_pause()',true);
try {
const token=request('POST','/login',null,{email:'routing-admin@example.test',password:'DisposableTest123!'}).body.token;
const category=Number(sql("INSERT INTO categories (name,slug,created_at,updated_at) VALUES ('Probe category','probe-category',now(),now()) RETURNING id").split('\n')[0]);
const product=Number(sql(`INSERT INTO products (title,description,price,category_id,created_at,updated_at) VALUES ('Probe product','Probe',10,${category},now(),now()) RETURNING id`).split('\n')[0]);
const edit=request('GET',`/admin/products/${product}/edit`,token);
assert.equal(edit.status,200); assert.equal(edit.body.data.product.category.id,category); assert.ok(edit.body.data.categories.some(c=>c.id===category));
const stock=request('PATCH',`/admin/products/${product}/stock`,token,{stock_quantity:2}); assert.equal(stock.status,200); assert.equal(stock.body.data.category.id,category);
const order=Number(sql("INSERT INTO orders(user_id,total_amount,status,created_at,updated_at) VALUES (2,10,'pending',now(),now()) RETURNING id").split('\n')[0]);
sql(`INSERT INTO order_items(order_id,product_id,quantity,unit_price,created_at,updated_at) VALUES (${order},1,1,10,now(),now())`);
const unchanged=request('PATCH',`/admin/orders/${order}/status`,token,{status:'pending'});
assert.equal(unchanged.status,200); assert.equal(unchanged.body.data.items.length,1); assert.equal(Number(unchanged.body.data.total_amount),10);
const id='f951e170-a3d8-4674-aa38-7fdd59c13c91';
sql(`INSERT INTO notifications(id,type,notifiable_type,notifiable_id,data,created_at,updated_at) VALUES ('${id}','test','user',1,'{}',now(),now())`);
const notification=request('PATCH',`/notifications/${id}/read`,token);
assert.equal(notification.status,200); assert.equal(sql(`SELECT read_at IS NOT NULL FROM notifications WHERE id='${id}'`),'t');
console.log('PASS: paused replay preserves editor/stock category data, no-op order lines and notification marking.');
}finally{sql('SELECT pg_wal_replay_resume()',true);}
}


async function verifyLostFillLock(s) {
const sql=q=>p('exec',s.db,'psql','-U','novacart','-d','novacart_routing_perf','-At','-v','ON_ERROR_STOP=1','-c',q);
sql("UPDATE products SET title='Before lost lock' WHERE id=1");
p('exec',s.app,'php','/checks/cache-routing-check.php','drain');
p('exec',s.app,'rm','-f','/tmp/catalog-fill-ready','/tmp/catalog-fill-release');
let out='',err='';
const child=spawn('podman',['exec',s.app,'php','/checks/cache-routing-check.php','fill-race','1']);
child.stdout.on('data',d=>out+=d);child.stderr.on('data',d=>err+=d);
const done=new Promise((resolve,reject)=>child.on('exit',code=>code===0?resolve():reject(new Error(err))));
let ready=false;for(let i=0;i<100;i++){try{p('exec',s.app,'test','-f','/tmp/catalog-fill-ready');ready=true;break;}catch{}await delay(50);}
assert.ok(ready);
assert.equal(sql("SELECT pg_terminate_backend(pid) FROM pg_locks WHERE locktype='advisory' AND mode='ShareLock' AND granted AND pid<>pg_backend_pid()"),'t');
sql("UPDATE products SET title='After lost lock' WHERE id=1");
p('exec',s.app,'php','/checks/cache-routing-check.php','drain');
p('exec',s.app,'touch','/tmp/catalog-fill-release');await done;
assert.equal(JSON.parse(out).title,'After lost lock');
assert.equal(JSON.parse(p('exec',s.app,'php','/checks/cache-routing-check.php','fill-race','1')).title,'After lost lock');
assert.equal(sql('SELECT needs_invalidation FROM catalog_cache_state'),'f');
console.log('PASS: terminated control connection cannot resurrect a flushed snapshot; subsequent read stays fresh.');
}
