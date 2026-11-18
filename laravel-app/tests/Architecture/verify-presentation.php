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
$verbose = in_array('--verbose', $argv, true);

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
    $counts = [];
    $sizes = $verbose ? [1, 4, 8] : [1, 8];
    $totals = [];
    if ($verbose) {
        echo "\nN+1 demonstration: real order loading and batch enrichment\n";
        echo "Eight temporary orders, each containing a different product.\n";
        echo "Only SELECTs during loading/presentation are counted; setup is excluded.\n";
    }
    foreach ($sizes as $size) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $orders = Order::query()->where('user_id', $user->id)->with('items')->limit($size)->get();
        $app->make(OrderPresentation::class)->prepare($orders);
        $queries = DB::getQueryLog();
        $counts[] = count($queries);
        $totals[] = array_sum(array_column($queries, 'time'));
        DB::disableQueryLog();
        if ($verbose) {
            printf("\n--- Loading %d order(s): %d SQL queries ---\n", $size, count($queries));
            foreach ($queries as $index => $query) {
                printf("\n%d. %s\n", $index + 1, $query['query']);
                echo '   ID bindings: '.json_encode($query['bindings'])."\n";
                printf("   SQL time: %.3f ms\n", $query['time']);
            }
        }
        foreach ($orders as $order) {
            if ($order->items[0]->catalogTitle !== $id || $order->getRelation('customerProfile')['id'] !== $user->id) {
                throw new RuntimeException('Batch presentation returned incorrect catalog/profile data.');
            }
            if ($order->items[0]->isDirty('catalogTitle') || $order->items[0]->isDirty('product_title')) {
                throw new RuntimeException('Read-side catalog data leaked into persisted attributes.');
            }
        }
    }
    if (count(array_unique($counts)) !== 1) {
        throw new RuntimeException('Query count grew with order count: '.implode(', ', $counts));
    }
    if ($verbose) {
        echo "\nOrders returned | SQL queries | Summed SQL time\n";
        echo "----------------+-------------+----------------\n";
        foreach ($sizes as $index => $size) {
            printf("%15d | %11d | %11.3f ms\n", $size, $counts[$index], $totals[$index]);
        }
        echo "\nLook at WHERE ... IN (...): the ID list grows, not the number of queries.\n";
        echo "Queries fetch orders, lines, products, categories and customer profiles in batches.\n";
        echo "This is a focused presentation check, not a complete HTTP request trace.\n";
        echo "SQL times are illustrative; the k6 workload measures HTTP performance.\n";
    }
    echo "PASS: one and eight orders both use {$counts[0]} queries including catalog/profile enrichment.\n";
} finally {
    DB::disableQueryLog();
    DB::rollBack();
    if ($verbose) echo "Temporary fixtures rolled back; no test orders/products retained.\n";
}
