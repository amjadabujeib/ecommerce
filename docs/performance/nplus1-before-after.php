<?php

// Illustrative N+1 comparison on disposable data; not a historical application version.
// Run from laravel-app with APP_ENV=testing. Container: run as www-data.

use App\Modules\Orders\Models\Order;
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
if (DB::connection()->getDatabaseName() !== 'novacart_perf') {
    throw new RuntimeException('Use novacart_perf only.');
}

DB::beginTransaction();
try {
    $id = Str::uuid()->toString();
    $user = User::create(['username' => $id, 'email' => $id.'@example.com', 'password' => Str::random(32)]);
    $category = Category::create(['name' => $id]);
    for ($i = 0; $i < 8; $i++) {
        $product = Product::create(['title' => $id, 'description' => 'Fixture', 'price' => '1.00', 'category_id' => $category->id]);
        $order = Order::create(['user_id' => $user->id, 'total_amount' => '1.00', 'status' => 'pending']);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00']);
    }
    echo "Orders | BEFORE: separate line queries | AFTER: eager loading\n";
    foreach ([1, 4, 8] as $size) {
        $counts = [];
        foreach ([false, true] as $batched) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $query = Order::query()->where('user_id', $user->id)->orderBy('id')->limit($size);
                if ($batched) {
                    $query->with('items');
                }
                $orders = $query->get();
                $lines = 0;
                foreach ($orders as $order) {
                    // Explicitly model the inefficient one-query-per-order pattern.
                    $items = $batched ? $order->items : $order->items()->get();
                    $lines += $items->count();
                }
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            if ($orders->count() !== $size || $lines !== $size) {
                throw new RuntimeException('Incorrect order/line results.');
            }
        }
        if ($counts !== [1 + $size, 2]) {
            throw new RuntimeException('Unexpected query counts.');
        }
        printf("%6d | %28d | %19d\n", $size, $counts[0], $counts[1]);
    }
    echo "PASS: same order/line results; before = 1 + N queries, after = 2.\n";
    echo "Scope: orders and lines only; excludes product/category/customer enrichment.\n";
} finally {
    DB::disableQueryLog();
    DB::rollBack();
    echo "Temporary fixtures rolled back.\n";
}
