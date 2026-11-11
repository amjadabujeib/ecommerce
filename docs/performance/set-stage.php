<?php

// Disposable benchmark control only; never prints cached configuration/secrets.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, 'Stage control failed: '.$error->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_perf')) {
    throw new RuntimeException('Requires testing environment and a database ending in _perf.');
}
$stages = [
    'indexes-control' => [false, false, false],
    'replica-only' => [true, false, false],
    'replica-cache' => [true, true, true],
    'diagnostics' => [true, true, true],
];
$stage = $argv[1] ?? '';
if (! isset($stages[$stage])) throw new InvalidArgumentException('Unknown benchmark stage.');
$path = $app->getCachedConfigPath();
if (! is_file($path)) throw new RuntimeException('Requires an isolated app with cached configuration.');
$config = require $path;
[$config['performance']['replica'], $config['performance']['catalog_cache'], $config['performance']['enabled']] = $stages[$stage];
$config['performance']['diagnostics'] = $stage === 'diagnostics';
file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL, LOCK_EX);
echo "Selected {$stage}. Reload the isolated FPM master before issuing requests.\n";
