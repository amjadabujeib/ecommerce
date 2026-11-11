<?php

// APP_ENV=testing; run as www-data from laravel-app on a disposable stack.
// Modes: prepare, unavailable, recover. Stop inventory between prepare and
// unavailable; restart before recover. State contains fixture IDs only.
use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Orders\Services\ReservationRecovery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL); exit(1);
});
if (! $app->environment('testing')) {
    throw new RuntimeException('Use APP_ENV=testing and disposable databases.');
}
function verify(bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
}
$inventory = app(InventoryGateway::class);
$recovery = app(ReservationRecovery::class);
$file = storage_path('app/recovery-demo.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $id = (string) Str::uuid();
    // Inventory accepts scalar catalog IDs; this test needs no catalog writes.
    $product = 1900000000;
    $inventory->setStock($product, 5);
    $recovery->begin($id);
    $inventory->reserve($id, [$product => 2]);
    file_put_contents($file, json_encode(compact('id', 'product'), JSON_THROW_ON_ERROR));
    verify($inventory->availability([$product])[$product] === 3, 'Remote reservation did not take stock.');
    echo "PASS: durable intent and real remote reservation survive this process exiting.\n";
} elseif (in_array($mode, ['unavailable', 'recover'], true)) {
    ['id' => $id, 'product' => $product] = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    if ($mode === 'unavailable') {
        // Age the intent only after inventory is stopped. Otherwise the live
        // scheduler could correctly recover it before the outage phase.
        DB::table('checkout_reservations')->where('id', $id)->update(['created_at' => now()->subMinutes(6)]);
    }
    $recovery->recover();
    $state = DB::table('checkout_reservations')->where('id', $id)->value('state');
    if ($mode === 'unavailable') {
        verify($state === 'pending', 'Failed release lost its durable intent.');
        echo "PASS: unavailable inventory leaves a durable pending recovery record.\n";
    } else {
        verify($state === 'released', 'Recovery did not complete.');
        verify($inventory->availability([$product])[$product] === 5, 'Recovery did not restore stock.');
        $recovery->recover();
        verify($inventory->availability([$product])[$product] === 5, 'Repeat recovery changed stock.');
        $inventory->setStock($product, 0);
        DB::table('checkout_reservations')->where('id', $id)->delete(); unlink($file);
        echo "PASS: real gRPC recovery restores stock once after a failed release and process restart.\n";
    }
} else throw new RuntimeException('Use prepare, unavailable, or recover.');
