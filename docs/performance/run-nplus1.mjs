// Uses the existing disposable performance app; never starts or rebuilds services.
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const source = fileURLToPath(new URL('./nplus1-before-after.php', import.meta.url));
const container = 'novacart-perf_app_1';
const target = '/tmp/novacart-nplus1-before-after.php';
for (const args of [
    ['cp', source, `${container}:${target}`],
    ['exec', '--user', 'www-data', '-w', '/var/www/html', container, 'php', target],
]) {
    const result = spawnSync('docker', args, {stdio: 'inherit'});
    if (result.error) {
        console.error(result.error.message);
        process.exit(1);
    }
    if (result.status !== 0) process.exit(result.status ?? 1);
}
