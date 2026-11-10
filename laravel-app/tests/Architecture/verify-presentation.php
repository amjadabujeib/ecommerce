<?php

// Integration check with disposable migrated core DB; no PHPUnit/Faker needed.
// Run from laravel-app with APP_ENV=testing. Container: run as www-data.

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderPresentation;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing')) {
    throw new RuntimeException('Use APP_ENV=testing with a disposable database.');
}

DB::beginTransaction();
try {
    $id = Str::uuid()->toString();
    $user = User::create(['username' => $id, 'email' => $id.'@example.com', 'password' => Str::random(32)]);
    $category = Category::create(['name' => $id]);
    $product = Product::create(['title' => $id, 'description' => 'Fixture', 'price' => '1.00', 'category_id' => $category->id]);
    for ($i = 0; $i < 8; $i++) {
        $order = Order::create(['user_id' => $user->id, 'total_amount' => '1.00', 'status' => 'pending']);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00']);
    }
    $counts = [];
    foreach ([1, 8] as $size) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $orders = Order::query()->where('user_id', $user->id)->with('items')->limit($size)->get();
        $app->make(OrderPresentation::class)->prepare($orders);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach ($orders as $order) {
            if ($order->items[0]->catalogTitle !== $id || $order->getRelation('customerProfile')['id'] !== $user->id) {
                throw new RuntimeException('Batch presentation returned incorrect catalog/profile data.');
            }
            if ($order->items[0]->isDirty('catalogTitle') || $order->items[0]->isDirty('product_title')) {
                throw new RuntimeException('Read-side catalog data leaked into persisted attributes.');
            }
        }
    }
    if ($counts[0] !== $counts[1]) {
        throw new RuntimeException('Query count grew with order count: '.implode(', ', $counts));
    }
    echo "PASS: one and eight orders both use {$counts[0]} queries including catalog/profile enrichment.\n";
} finally {
    DB::disableQueryLog();
    DB::rollBack();
}
