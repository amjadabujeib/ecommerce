// Runs the actual k6 binary sequentially; Node only assembles the four reports.
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

assert.ok(process.env.ADMIN_PASSWORD, 'Set the performance ADMIN_PASSWORD first.');
const executable = process.env.K6_BIN || 'k6';
const version = spawnSync(executable, ['version'], {encoding: 'utf8'});
assert.equal(version.error, undefined, 'k6 is unavailable; install it or set K6_BIN to its executable.');
assert.equal(version.status, 0, 'Could not read k6 version.');
const stage = process.env.STAGE || 'unlabelled';
const output = resolve(process.env.OUTPUT || `k6-${stage}.json`);
await mkdir(dirname(output), {recursive: true});
const script = fileURLToPath(new URL('./load.k6.js', import.meta.url));
const results = [];
let metadata;
console.log(`\n${version.stdout.trim()}\nStage: ${stage}\n`);
for (const workload of ['catalog', 'product', 'admin_orders', 'customer_orders']) {
    const part = output + `.${workload}.json`;
    console.log(`Running ${workload} ...`);
    const run = spawnSync(executable, ['run', '--no-usage-report', '--no-color', '--quiet', script], {
        env: {...process.env, WORKLOAD: workload, OUTPUT: part}, stdio: 'inherit',
    });
    assert.equal(run.error, undefined, `Could not execute k6 for ${workload}.`);
    assert.equal(run.status, 0, `${workload} failed; no valid combined report was produced. Do not use an older report at the output path.`);
    const report = JSON.parse(await readFile(part, 'utf8'));
    assert.equal(report.valid, true, `${workload} produced an invalid/empty measurement.`);
    metadata = report;
    results.push(...report.results);
}
const report = {...metadata, tool_version: version.stdout.trim(), results};
await writeFile(output, JSON.stringify(report, null, 2) + '\n');
const table = [
    `\n${stage} — k6 results (all measured responses HTTP 200)`,
    'Endpoint             p50 ms     p95 ms     p99 ms   Requests/s',
    '------------------------------------------------------------',
    ...results.map(r => r.workload.padEnd(18)
        + [r.p50_ms, r.p95_ms, r.p99_ms, r.requests_per_second]
            .map(n => n.toFixed(2).padStart(11)).join('')),
    `\nSaved: ${output}\n`,
].join('\n');
console.log(table);
await writeFile(output + '.txt', table);
