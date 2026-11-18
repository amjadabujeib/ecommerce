<?php

// Invoked only by the authorized disposable requirement 4 integration harness.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment('testing') || ! str_starts_with(config('database.connections.pgsql.database'), 'novacart_r4_test')) {
    throw new RuntimeException('Disposable requirement 4 test environment required.');
}

$client = $app->make(Illuminate\Contracts\Broadcasting\Factory::class)->connection('reverb');
$client->broadcast(['private-regression-probe'], 'probe', []);
file_put_contents('/tmp/reverb-address-ready', '1');
$deadline = microtime(true) + 30;
while (! is_file('/tmp/reverb-address-go') && microtime(true) < $deadline) {
    usleep(100000);
}
if (! is_file('/tmp/reverb-address-go')) {
    throw new RuntimeException('The harness did not complete the controlled address change.');
}
// Reuse exactly the same HTTP client after the service gets another address.
$client->broadcast(['private-regression-probe'], 'probe', []);
echo "PASS: retained broadcaster resolves Reverb's changed service address.\n";
