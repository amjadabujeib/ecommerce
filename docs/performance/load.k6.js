// Local evaluation workload. Run directly with k6, or use run-k6.mjs for all endpoints.
import http from 'k6/http';
import {check, fail} from 'k6';
import exec from 'k6/execution';
import {Counter, Gauge, Rate, Trend} from 'k6/metrics';

const base = (__ENV.BASE_URL || 'http://127.0.0.1:18090/api/v1').replace(/\/$/, '');
const localPattern = __ENV.DISPOSABLE_CONFIRM === 'cache-routing'
    ? /^http:\/\/(127\.0\.0\.1|localhost):[0-9]{1,5}\/api\/v1$/
    : /^http:\/\/(127\.0\.0\.1|localhost):18090\/api\/v1$/;
if (!localPattern.test(base)) {
    throw new Error('Use the local performance deployment on port 18090.');
}
function integer(name, fallback, min, max) {
    const value = Number(__ENV[name] ?? fallback);
    if (!Number.isInteger(value) || value < min || value > max) {
        throw new Error(`${name} must be an integer between ${min} and ${max}.`);
    }
    return value;
}
const concurrency = integer('CONCURRENCY', 4, 1, 16);
const warmup = integer('WARMUP_SECONDS', 3, 0, 60);
const seconds = integer('DURATION_SECONDS', 20, 5, 300);
const workloads = {
    catalog: {path: '/?page=1'},
    product: {path: '/products/10001'},
    admin_orders: {path: '/admin/orders?page=1', role: 'admin'},
    customer_orders: {path: '/orders?page=1', role: 'customer'},
};
const workload = __ENV.WORKLOAD || 'catalog';
if (!Object.hasOwn(workloads, workload)) throw new Error('Unknown WORKLOAD.');
const endpoint = workloads[workload];
const latency = new Trend('measured_latency_ms', true);
const requests = new Counter('measured_requests');
const successes = new Counter('measured_successes');
const errors = new Rate('measured_errors');
const elapsed = new Gauge('measured_elapsed_seconds');

export const options = {
    scenarios: {
        endpoint: {
            executor: 'constant-vus', vus: concurrency,
            duration: `${warmup + seconds}s`, gracefulStop: '35s',
        },
    },
    // No external JS imports or cloud output. Bodies are received but not retained.
    discardResponseBodies: true,
    summaryTrendStats: ['med', 'p(95)', 'p(99)', 'count'],
    thresholds: {
        measured_requests: ['count>0'],
        measured_errors: ['rate==0'],
        checks: ['rate==1'],
    },
};

export function setup() {
    if (!endpoint.role) return {};
    if (endpoint.role === 'admin' && !__ENV.ADMIN_PASSWORD) {
        throw new Error('Set ADMIN_PASSWORD for the performance administrator.');
    }
    const credentials = endpoint.role === 'admin'
        ? {email: __ENV.ADMIN_EMAIL || 'admin@example.com', password: __ENV.ADMIN_PASSWORD}
        : {email: 'perf-1@example.test', password: 'PerformanceOnly123!'};
    const response = http.post(`${base}/login`, JSON.stringify(credentials), {
        headers: {'Content-Type': 'application/json', Accept: 'application/json'},
        responseType: 'text', timeout: '10s', redirects: 0,
        tags: {phase: 'authentication'},
    });
    if (response.status !== 200) {
        // Never log response bodies or credentials: a successful response contains a JWT.
        throw new Error(`${endpoint.role} login returned HTTP ${response.status}; check test credentials/fixtures.`);
    }
    const token = response.json('token');
    if (typeof token !== 'string' || !token) throw new Error('Login returned no token.');
    return {token};
}

export default function (data) {
    const started = Date.now();
    const measurementStart = exec.scenario.startTime + warmup * 1000;
    const measured = started >= measurementStart;
    const response = http.get(base + endpoint.path, {
        headers: {Accept: 'application/json', ...(data.token ? {Authorization: `Bearer ${data.token}`} : {})},
        timeout: '30s', redirects: 0,
        tags: {workload, phase: measured ? 'measurement' : 'warmup'},
    });
    const finished = Date.now();
    const ok = check(response, {'HTTP 200': r => r.status === 200});
    if (measured) {
        // Wall-clock latency includes connection setup and receiving the response.
        latency.add(finished - started);
        requests.add(1);
        successes.add(ok ? 1 : 0);
        errors.add(!ok);
        elapsed.add((finished - measurementStart) / 1000);
    }
    // Stop bad runs early, including failures during warmup. Don't report fast errors as wins.
    if (!ok) {
        exec.test.abort(`${workload} returned HTTP ${response.status}; comparison invalid.`);
        fail('Unexpected response');
    }
}

export function handleSummary(data) {
    const values = name => data.metrics[name]?.values || {};
    const count = values('measured_requests').count || 0;
    const successful = values('measured_successes').count || 0;
    const duration = values('measured_elapsed_seconds').max || 0;
    const valid = count > 0 && count === successful
        && values('checks').rate === 1
        && Number.isFinite(values('measured_latency_ms').med);
    const row = {
        workload, path: endpoint.path, requests: count,
        elapsed_seconds: duration,
        requests_per_second: duration > 0 ? count / duration : 0,
        p50_ms: values('measured_latency_ms').med ?? null,
        p95_ms: values('measured_latency_ms')['p(95)'] ?? null,
        p99_ms: values('measured_latency_ms')['p(99)'] ?? null,
        statuses: {'200': successful,
            ...(count > successful ? {non_200_or_transport_error: count - successful} : {})},
    };
    const report = {
        tool: 'k6', stage: __ENV.STAGE || 'unlabelled', date: new Date().toISOString(),
        base_url: base, concurrency, warmup_seconds: warmup, duration_seconds: seconds,
        valid, results: [row],
    };
    const number = n => n === null ? 'N/A' : n.toFixed(2);
    const text = [
        `\n${report.stage} / ${workload}: ${valid ? 'PASS' : 'INVALID'}`,
        `Requests: ${count} | HTTP 200: ${successful}`,
        `p50: ${number(row.p50_ms)} ms | p95: ${number(row.p95_ms)} ms | p99: ${number(row.p99_ms)} ms`,
        `Throughput: ${number(row.requests_per_second)} requests/s\n`,
    ].join('\n');
    const outputs = {stdout: text};
    if (__ENV.OUTPUT) {
        outputs[__ENV.OUTPUT] = JSON.stringify(report, null, 2) + '\n';
        outputs[__ENV.OUTPUT + '.metrics.json'] = JSON.stringify(data, null, 2) + '\n';
    }
    return outputs;
}
