<?php

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

if (! app()->environment('testing') || ! str_starts_with(config('database.connections.pgsql.database'), 'novacart_r4_test')) {
    throw new RuntimeException('Disposable test database required.');
}
$operation = $argv[1] ?? 'fixture';
if ($operation === 'fixture') {
    $admin = User::factory()->admin()->create(['email' => 'admin@example.test', 'password' => 'DisposableTest123!']);
    $owner = User::factory()->create(['email' => 'controlled@example.test', 'password' => 'DisposableTest123!']);
    User::factory()->create(['email' => 'other@example.test', 'password' => 'DisposableTest123!']);
    $category = Category::query()->create(['name' => 'R4 disposable', 'slug' => 'r4-disposable']);
    $product = Product::query()->create(['title' => 'R4 fixture', 'description' => 'Disposable', 'price' => '12.50', 'category_id' => $category->id]);
    app(InventoryGateway::class)->setStock($product->id, 100);
    echo json_encode(['product' => $product->id, 'owner' => $owner->id, 'admin' => $admin->id]);
} elseif ($operation === 'state') {
    echo json_encode([
        'outbox' => DB::table('message_outbox')->orderBy('created_at')->get(),
        'deliveries' => DB::table('notification_deliveries')->orderBy('id')->get(),
        'orders' => DB::table('orders')->orderBy('id')->get(),
        'notifications' => DB::table('notifications')->count(),
    ]);
} elseif ($operation === 'queue-state') {
    echo json_encode(app(App\Support\Messaging\RabbitBroker::class)->inspect('mail'));
} elseif ($operation === 'retry-now') {
    DB::table('message_outbox')->whereNull('published_at')->update(['next_attempt_at' => now()->subSecond(), 'leased_until' => null]);
} elseif ($operation === 'expire-leases') {
    DB::table('notification_deliveries')->where('state', 'processing')->update(['leased_until' => now()->subSecond()]);
} elseif ($operation === 'duplicate') {
    $row = DB::table('message_outbox')->where('type', 'order.created')->orderBy('created_at')->first();
    app(App\Support\Messaging\RabbitBroker::class)->publishEvent(json_decode($row->payload, true));
}
