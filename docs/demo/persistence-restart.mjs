// Disposable stack only: stops/restarts all six containers and checks stored
// orders, catalog stock and an existing JWT. Set CONTAINER_PREFIX (names must
// end in web, scheduler, app, inventory, db, inventory-db), BASE_URL and ADMIN_PASSWORD.
import assert from 'node:assert/strict';
import {execFileSync as exec} from 'node:child_process';
import {setTimeout as sleep} from 'node:timers/promises';
const base = process.env.BASE_URL || 'http://127.0.0.1:8080/api/v1';
const prefix = process.env.CONTAINER_PREFIX;
assert.ok(prefix && process.env.ADMIN_PASSWORD, 'Set CONTAINER_PREFIX and ADMIN_PASSWORD for disposable containers.');
let token;
async function api(path, options={}) {
 const response = await fetch(base+path,{...options,headers:{Accept:'application/json','Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})},signal:AbortSignal.timeout(5000)});
 assert.equal(response.status,200, `${path}: ${response.status}`);return response.json();
}
token=(await api('/login',{method:'POST',body:JSON.stringify({email:process.env.ADMIN_EMAIL || 'admin@example.com',password:process.env.ADMIN_PASSWORD})})).token;
const before=await api('/admin/orders');
const stocks=await api('/');
assert.ok(stocks.data.every(product => product.stock_quantity !== null), 'Inventory must be available before the restart.');
for(const name of ['web','scheduler','app','inventory','db','inventory-db'])exec('docker',['stop',prefix+name],{stdio:'ignore'});
for(const name of ['db','inventory-db'])exec('docker',['start',prefix+name],{stdio:'ignore'});
for(const [name,user,db] of [['db','novacart','novacart'],['inventory-db','inventory','inventory']]){
 for(let i=0;i<30;i++){try{exec('docker',['exec',prefix+name,'pg_isready','-U',user,'-d',db],{stdio:'ignore'});break}catch{await sleep(500)}}
}
exec('docker',['start',prefix+'inventory'],{stdio:'ignore'});
exec('docker',['start',prefix+'app'],{stdio:'ignore'});
for(let i=0;i<30;i++){try{exec('docker',['exec',prefix+'app','php','-r',"exit(@fsockopen('127.0.0.1',9000)?0:1);"],{stdio:'ignore'});break}catch{await sleep(500)}}
exec('docker',['start',prefix+'scheduler'],{stdio:'ignore'});
exec('docker',['start',prefix+'web'],{stdio:'ignore'});
let after;for(let i=0;i<30;i++){try{after=await api('/admin/orders');break}catch{await sleep(500)}}
assert.deepEqual(after,before,'Orders changed after complete stack restart');
assert.deepEqual((await api('/')).data,stocks.data,'Product stock changed after complete stack restart');
assert.equal((await api('/me')).data.role,'admin');
console.log(`PASS: all six containers restarted; ${before.meta.total} orders, catalog stock, and an existing JWT survived persistent volumes.`);
