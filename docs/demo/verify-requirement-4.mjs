// Explicitly authorized disposable services, cached images only, no external mail.
// DISPOSABLE_CONFIRM=requirement-4 node docs/demo/verify-requirement-4.mjs
import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
assert.equal(process.env.DISPOSABLE_CONFIRM, 'requirement-4', 'Authorize isolated service tests first.');
const root = fileURLToPath(new URL('../../', import.meta.url));
process.chdir(root);
const p = (...args) => execFileSync('podman', args, {encoding:'utf8',maxBuffer:1e6,stdio:['ignore','pipe','pipe']}).trim();
const prefix = `novacart-r4-test-${process.pid}`;
const names = Object.fromEntries(['network','db','rabbit','inventory','app','reverb','smtp'].map(x=>[x,`${prefix}-${x}`]));
const rabbitImage = 'docker.io/library/rabbitmq:4.4.0-management-alpine@sha256:598b474e2511f37a7408f3fa580f002684638f4ad6401395102b51e3209a9a51';
const phpImage = 'localhost/novacart-r4:local';
for (const image of [phpImage,rabbitImage,'docker.io/library/postgres:17-alpine','localhost/novacart-perf_inventory:latest','docker.io/library/node:22-alpine']) p('image','exists',image);
const delay = ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function ready(name, command) {
    for(let i=0;i<150;i++){try{p('exec',name,...command);return;}catch{await delay(200);}}
    throw new Error(`Readiness failed: ${name}`);
}
const env = {APP_ENV:'testing',APP_DEBUG:'false',APP_KEY:'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',DB_CONNECTION:'pgsql',DB_HOST:'db',DB_PORT:'5432',DB_DATABASE:'novacart_r4_test',DB_USERNAME:'novacart',DB_PASSWORD:'disposable-only',DB_URL:'',CACHE_STORE:'database',SESSION_DRIVER:'array',QUEUE_CONNECTION:'sync',JWT_SECRET:'disposable-test-secret-0123456789abcdef',INVENTORY_GRPC_ADDRESS:'inventory:50051',MESSAGING_ENABLED:'true',RABBITMQ_HOST:'rabbitmq',RABBITMQ_USER:'novacart',RABBITMQ_PASSWORD:'disposable-only',RABBITMQ_VHOST:'novacart',REVERB_APP_ID:'novacart',REVERB_APP_KEY:'test-key',REVERB_APP_SECRET:'disposable-signing-secret',REVERB_HOST:'reverb',REVERB_PORT:'8081',REVERB_SCHEME:'http',REVERB_ALLOWED_ORIGINS:'127.0.0.1,localhost',MAIL_MAILER:'smtp',MAIL_HOST:'smtp',MAIL_PORT:'2525',MAIL_SCHEME:'smtp',MAIL_FROM_ADDRESS:'sender@example.test',MAIL_RECIPIENT_ALLOWLIST:'controlled@example.test',MAIL_TIMEOUT:'10',MESSAGING_RETRY_DELAYS:'1,2,3',LOG_CHANNEL:'stderr',PERFORMANCE_LOAD_TESTING:'true',PHP_CLI_SERVER_WORKERS:'4'};
const phpArgs = ()=>['--network',names.network,'-v',`${root}/laravel-app:/var/www/html:Z`,'-v',`${root}/docs/demo:/checks:Z`,'--tmpfs','/var/www/html/bootstrap/cache:rw,mode=1777','--tmpfs','/var/www/html/storage:rw,mode=1777',...Object.entries(env).flatMap(([k,v])=>['-e',`${k}=${v}`]),'--entrypoint','sh',phpImage];
const php = (...args)=>p('exec',names.app,'php',...args);
const state = ()=>JSON.parse(php('/checks/requirement-4-fixture.php','state'));
const consume = path=>php('artisan','messaging:consume',path,'--once');
const sockets=[];
let base;
async function api(method,path,token,body,status=200){
    const r=await fetch(base+path,{method,headers:{Accept:'application/json','Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})},body:body===undefined?undefined:JSON.stringify(body),signal:AbortSignal.timeout(10000)});
    const txt=await r.text();assert.equal(r.status,status,`${method} ${path}: ${txt.slice(0,1000)}`);return txt?JSON.parse(txt):null;
}
async function socket(token,channel){
    const ws=new WebSocket(`ws://127.0.0.1:${p('port',names.reverb,'8081/tcp').split(':').at(-1)}/app/test-key?protocol=7&client=js&version=8.6.0`, {headers:{Origin:'http://127.0.0.1'}});
    sockets.push(ws);const messages=[];ws.addEventListener('message',e=>messages.push(JSON.parse(e.data)));
    const wait=async predicate=>{for(let i=0;i<100;i++){const m=messages.find(predicate);if(m)return m;await delay(25);}throw new Error(`Missing socket message: ${JSON.stringify(messages)}`);};
    const connected=await wait(m=>m.event==='pusher:connection_established');
    const id=JSON.parse(connected.data).socket_id;
    const auth=await api('POST','/realtime/auth',token,{socket_id:id,channel_name:channel});
    ws.send(JSON.stringify({event:'pusher:subscribe',data:{channel,auth:auth.auth}}));
    await wait(m=>m.event==='pusher_internal:subscription_succeeded');
    return {ws,messages,wait,id};
}
try {
    p('network','create','--internal',names.network);
    p('run','-d','--pull=never','--name',names.db,'--network',names.network,'--network-alias','db','--tmpfs','/var/lib/postgresql/data:rw','-e','POSTGRES_DB=novacart_r4_test','-e','POSTGRES_USER=novacart','-e','POSTGRES_PASSWORD=disposable-only','docker.io/library/postgres:17-alpine');
    p('run','-d','--pull=never','--name',names.rabbit,'--network',names.network,'--network-alias','rabbitmq','--hostname','r4-rabbit','-e','RABBITMQ_DEFAULT_USER=novacart','-e','RABBITMQ_DEFAULT_PASS=disposable-only','-e','RABBITMQ_DEFAULT_VHOST=novacart','-e','RABBITMQ_SERVER_ADDITIONAL_ERL_ARGS=+S 2:2 +A 4',rabbitImage);
    await ready(names.db,['pg_isready','-h','127.0.0.1','-U','novacart']);
    p('exec',names.db,'psql','-U','novacart','-d','novacart_r4_test','-c','CREATE DATABASE inventory');
    p('run','-d','--pull=never','--name',names.inventory,'--network',names.network,'--network-alias','inventory','-e','DATABASE_URL=postgres://novacart:disposable-only@db:5432/inventory','localhost/novacart-perf_inventory:latest');
    // Local SMTP server: real wire protocol, controlled failure/hold modes, no external access.
    p('run','-d','--pull=never','--name',names.smtp,'--network',names.network,'--network-alias','smtp','-v',`${root}/docs/demo:/checks:Z`,'docker.io/library/node:22-alpine','node','/checks/smtp-fixture.mjs');
    p('run','-d','--pull=never','--name',names.app,'-p','127.0.0.1::8000',...phpArgs(),'-c','mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs; exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload');
    php('artisan','migrate','--force','--no-interaction');
    await ready(names.inventory,['node','src/healthcheck.js']);
    const fixture=JSON.parse(php('/checks/requirement-4-fixture.php'));
    await ready(names.rabbit,['rabbitmq-diagnostics','-q','check_running']);
    console.log(php('artisan','messaging:setup'));
    p('run','-d','--pull=never','--name',names.reverb,'--network-alias','reverb','-p','127.0.0.1::8081',...phpArgs(),'-c','mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs; exec php artisan reverb:start --host=0.0.0.0 --port=8081');
    await ready(names.reverb,['php','-r',"exit(@fsockopen('127.0.0.1',8081) ? 0 : 1);"]);
    base=`http://127.0.0.1:${p('port',names.app,'8000/tcp').split(':').at(-1)}/api/v1`;
    const login=async email=>(await api('POST','/login',null,{email,password:'DisposableTest123!'})).token;
    const admin=await login('admin@example.test'), customer=await login('controlled@example.test'), other=await login('other@example.test');
    const adminSocket=await socket(admin,'private-admin.orders');
    async function place(){await api('POST',`/cart/${fixture.product}`,customer,{quantity:1});return (await api('POST','/orders',customer,{},201)).data;}
    const order=await place();
    assert.equal(state().deliveries.length,0,'Email must not execute inline.');
    consume('realtime');
    await adminSocket.wait(m=>m.event==='order.created');
    assert.equal(state().deliveries.find(x=>x.handler==='realtime').state,'succeeded');
    const tracking=await socket(customer,`private-orders.${order.id}`);
    await api('POST','/realtime/auth',other,{socket_id:'77.88',channel_name:`private-orders.${order.id}`},403);
    await api('POST','/realtime/auth',customer,{socket_id:'77.88',channel_name:'private-admin.orders'},403);
    consume('mail');
    assert.equal(state().deliveries.find(x=>x.handler==='mail').state,'succeeded');
    const mailCount=()=>Number(p('exec',names.smtp,'node','-e',"const fs=require('fs'); console.log(fs.existsSync('/tmp/mail.json')?JSON.parse(fs.readFileSync('/tmp/mail.json')).length:0)"));
    assert.equal(mailCount(),1);
    php('/checks/requirement-4-fixture.php','duplicate');consume('mail');consume('realtime');assert.equal(mailCount(),1,'Duplicate delivery must not resend accepted mail.');
    await api('PATCH',`/admin/orders/${order.id}/status`,admin,{status:'completed'});consume('realtime');
    const status=await tracking.wait(m=>m.event==='order.status_changed');assert.equal(JSON.parse(status.data).order.status,'completed');
    // Revocation is checked by Reverb immediately before delivery, not only at subscription time.
    await api('POST','/logout',customer,{});
    await api('PATCH',`/admin/orders/${order.id}/status`,admin,{status:'cancelled'});consume('realtime');await delay(200);
    assert.equal(tracking.messages.filter(m=>m.event==='order.status_changed').length,1,'Revoked socket received private update.');
    console.log('PASS: committed outbox, independent consumers, SMTP, duplicates, real WebSockets and revoked-socket denial.');
    // Broker outage: committed orders remain successful and relay later recovers.
    const freshCustomer=await login('controlled@example.test');
    // Keep a confirmed message pending to prove broker storage survives restart too.
    await api('POST',`/cart/${fixture.product}`,freshCustomer,{quantity:1});
    const queued=(await api('POST','/orders',freshCustomer,{},201)).data;
    assert.equal(JSON.parse(php('/checks/requirement-4-fixture.php','queue-state')).find(x=>x.queue==='novacart.mail.v1').ready,1);
    p('stop','-t','2',names.rabbit);
    await api('POST',`/cart/${fixture.product}`,freshCustomer,{quantity:1});
    const outage=(await api('POST','/orders',freshCustomer,{},201)).data;
    assert.equal(state().outbox.filter(x=>x.published_at===null).length,1);
    p('start',names.rabbit);await ready(names.rabbit,['rabbitmq-diagnostics','-q','check_running']);
    assert.equal(JSON.parse(php('/checks/requirement-4-fixture.php','queue-state')).find(x=>x.queue==='novacart.mail.v1').ready,1,'Confirmed queued mail must survive broker restart.');
    php('/checks/requirement-4-fixture.php','retry-now');php('artisan','messaging:relay','--once');
    for(let i=0;i<2;i++){consume('mail');consume('realtime');}
    assert.equal(state().outbox.filter(x=>x.published_at===null).length,0);assert.equal(mailCount(),3);
    console.log('PASS: broker restart persistence and recovery after post-commit publication failure.');
    // Permanent SMTP rejection: actual TTL queues and DLQ, with short test-only delays provisioned below.
    p('exec',names.smtp,'sh','-c','touch /tmp/fail');
    await api('POST',`/cart/${fixture.product}`,freshCustomer,{quantity:1});
    const failed=(await api('POST','/orders',freshCustomer,{},201)).data;
    consume('mail');
    let entry=state().deliveries.find(x=>x.handler==='mail'&&x.event_id===state().outbox.find(y=>JSON.parse(y.payload).order_id===failed.id).event_id);
    assert.equal(entry.state,'waiting');assert.equal(entry.attempts,1);
    await delay(1400);p('exec',names.smtp,'rm','/tmp/fail');consume('mail');
    entry=state().deliveries.find(x=>x.id===entry.id);assert.equal(entry.state,'succeeded');assert.equal(entry.attempts,2);
    console.log('PASS: SMTP failure transfers durably to retry queue and succeeds after TTL.');
    // A poison event exhausts the bounded policy and remains in a real RabbitMQ DLQ.
    p('exec',names.smtp,'sh','-c','touch /tmp/fail');
    await api('POST',`/cart/${fixture.product}`,freshCustomer,{quantity:1});
    const poison=(await api('POST','/orders',freshCustomer,{},201)).data;
    consume('mail');
    for(const pause of [1400,2400,3400]){await delay(pause);consume('mail');}
    const poisonEvent=state().outbox.find(x=>JSON.parse(x.payload).order_id===poison.id).event_id;
    let poisonDelivery=state().deliveries.find(x=>x.event_id===poisonEvent&&x.handler==='mail');
    assert.equal(poisonDelivery.state,'deadlettered');assert.equal(poisonDelivery.attempts,4);
    const inspection=php('artisan','messaging:dlq','mail');assert.match(inspection,/novacart.mail.v1.dlq/);
    const queues=JSON.parse(php('/checks/requirement-4-fixture.php','queue-state'));
    assert.equal(queues.find(x=>x.queue==='novacart.mail.v1.dlq').ready,1,'Terminal event must be in the actual broker DLQ.');
    p('exec',names.smtp,'rm','/tmp/fail');
    await api('POST',`/cart/${fixture.product}`,freshCustomer,{quantity:1});
    await api('POST','/orders',freshCustomer,{},201);consume('mail');
    const beforeReplay=mailCount();
    php('artisan','messaging:dlq','mail',`--replay=${poisonEvent}`);consume('mail');
    assert.equal(mailCount(),beforeReplay+1);
    poisonDelivery=state().deliveries.find(x=>x.id===poisonDelivery.id);assert.equal(poisonDelivery.state,'succeeded');
    console.log('PASS: bounded failures reach real DLQ; healthy mail progresses; explicit replay recovers terminal delivery.');
    // Kill a consumer during SMTP before acceptance/ACK, then recover the broker redelivery.
    p('exec',names.smtp,'sh','-c','touch /tmp/hold; rm -f /tmp/receiving');
    await api('POST',`/cart/${fixture.product}`,freshCustomer,{quantity:1});
    const interrupted=(await api('POST','/orders',freshCustomer,{},201)).data;
    const child=spawn('podman',['exec',names.app,'sh','-c','echo $$ > /tmp/consumer.pid; exec php artisan messaging:consume mail --once'],{stdio:'ignore'});
    await ready(names.smtp,['test','-f','/tmp/receiving']);
    const interruptedId=state().outbox.find(x=>JSON.parse(x.payload).order_id===interrupted.id).event_id;
    assert.equal(state().deliveries.find(x=>x.event_id===interruptedId&&x.handler==='mail').state,'processing');
    const killed=new Promise(resolve=>child.once('exit',resolve));
    php('-r',"posix_kill((int)file_get_contents('/tmp/consumer.pid'), SIGKILL);");
    await killed;
    p('exec',names.smtp,'rm','/tmp/hold');
    // Age the bounded SQL lease instead of waiting 90 seconds in this disposable test.
    php('/checks/requirement-4-fixture.php','expire-leases');
    consume('mail');
    assert.equal(state().deliveries.find(x=>x.event_id===interruptedId&&x.handler==='mail').state,'succeeded');
    console.log('PASS: process death before SMTP acceptance/ACK leaves recoverable work and redelivery succeeds after lease expiry.');
    // Preserve last-unit safety with the transactional outbox enabled.
    await api('PATCH',`/admin/products/${fixture.product}/stock`,admin,{stock_quantity:1});
    for(const token of [freshCustomer,other])await api('POST',`/cart/${fixture.product}`,token,{quantity:1});
    const beforeRace=state().orders.length;
    const beforeEvents=state().outbox.length;
    const racers=await Promise.all([freshCustomer,other].map(token=>fetch(base+'/orders',{method:'POST',headers:{Accept:'application/json',Authorization:`Bearer ${token}`},signal:AbortSignal.timeout(12000)})));
    assert.deepEqual(racers.map(r=>r.status).sort(),[201,409]);
    const loser=await racers.find(r=>r.status===409).json();assert.equal(loser.status,409);
    assert.equal(state().orders.length,beforeRace+1);
    assert.equal(state().outbox.length,beforeEvents+1);
    assert.equal((await api('GET',`/products/${fixture.product}`)).data.stock_quantity,0);
    console.log('PASS: messaging-enabled last-unit race has one 201, one 409, one order/event and zero final stock.');
    await verifyReverbAddressRecovery();


} catch(error) {
    for(const name of [names.app,names.reverb]){try{console.error(p('logs','--tail','25',name));}catch{}}
    throw error;
} finally {
    for(const ws of sockets)ws.close();
    for(const name of [names.reverb,names.app,names.smtp,names.inventory,names.rabbit,names.db]){try{p('rm','-f','-t','1',name);}catch{}}
    try{p('network','rm',names.network);}catch{}
}


async function verifyReverbAddressRecovery() {
    p('exec',names.app,'rm','-f','/tmp/reverb-address-ready','/tmp/reverb-address-go');
    const child=spawn('podman',['exec',names.app,'php','/checks/reverb-address-check.php']);
    let output='',error='';child.stdout.on('data',chunk=>output+=chunk);child.stderr.on('data',chunk=>error+=chunk);
    const exited=new Promise(resolve=>child.on('exit',resolve));
    await ready(names.app,['test','-f','/tmp/reverb-address-ready']);
    const info=JSON.parse(p('inspect',names.reverb))[0];
    const oldAddress=info.NetworkSettings.Networks[names.network].IPAddress;
    // The uniquely named test network contains only this harness's containers.
    // Assign an unused address to make the DNS change deterministic.
    const newAddress=oldAddress.split('.').slice(0,3).concat('240').join('.');
    assert.notEqual(oldAddress,newAddress);
    p('network','disconnect',names.network,names.reverb);
    p('network','connect','--alias','reverb','--ip',newAddress,names.network,names.reverb);
    await ready(names.reverb,['php','-r',"exit(@fsockopen('127.0.0.1',8081) ? 0 : 1);"]);
    p('exec',names.app,'touch','/tmp/reverb-address-go');
    assert.equal(await exited,0,error);
    assert.match(output,/retained broadcaster resolves/);console.log(output.trim());
}
