// Dependency-free closed-loop workload. Use identical options/data for every stage.
import assert from 'node:assert/strict';
import {performance} from 'node:perf_hooks';
import {writeFile} from 'node:fs/promises';
const base=(process.env.BASE_URL||'http://127.0.0.1:18090/api/v1').replace(/\/$/,'');
const concurrency=Number(process.env.CONCURRENCY||4),seconds=Number(process.env.DURATION_SECONDS||20),warmup=Number(process.env.WARMUP_SECONDS||3);
assert.ok(concurrency>=1&&concurrency<=16&&seconds>=5&&seconds<=300);
assert.ok(process.env.ADMIN_PASSWORD,'Set a disposable administrator password.');
async function login(email,password){const response=await fetch(base+'/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({email,password})});assert.equal(response.status,200);return(await response.json()).token;}
const admin=await login(process.env.ADMIN_EMAIL||'admin@example.com',process.env.ADMIN_PASSWORD);
const customer=await login('perf-1@example.test','PerformanceOnly123!');
const workloads=[['catalog','/?page=1',null],['product','/products/10001',null],['admin_orders','/admin/orders?page=1',admin],['customer_orders','/orders?page=1',customer]];
const results=[];
for(const [name,path,token] of workloads){
 async function phase(duration,record){const times=[],statuses={};const start=performance.now(),end=start+duration*1000;
  await Promise.all(Array.from({length:concurrency},async()=>{while(performance.now()<end){const begun=performance.now();let status='transport_error';try{const r=await fetch(base+path,{headers:{Accept:'application/json',...(token?{Authorization:`Bearer ${token}`}:{})},signal:AbortSignal.timeout(30000)});await r.arrayBuffer();status=String(r.status);}catch{}if(record){times.push(performance.now()-begun);statuses[status]=(statuses[status]||0)+1;}}}));
  const elapsed=(performance.now()-start)/1000;times.sort((a,b)=>a-b);const percentile=p=>times[Math.min(times.length-1,Math.ceil(times.length*p)-1)]??null;
  return {requests:times.length,elapsed_seconds:elapsed,requests_per_second:times.length/elapsed,p50_ms:percentile(.5),p95_ms:percentile(.95),p99_ms:percentile(.99),statuses};
 }
 await phase(warmup,false);const result={workload:name,path,...await phase(seconds,true)};results.push(result);console.log(JSON.stringify(result));
}
const report={stage:process.env.STAGE||'unlabelled',date:new Date().toISOString(),concurrency,duration_seconds:seconds,warmup_seconds:warmup,results};
if(process.env.OUTPUT)await writeFile(process.env.OUTPUT,JSON.stringify(report,null,2)+'\n');
assert.ok(results.every(r=>Object.keys(r.statuses).every(s=>s==='200')),'Non-200/transport errors invalidate the comparison.');
