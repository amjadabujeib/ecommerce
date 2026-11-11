<?php

// Historical benchmark fixtures only. Run from laravel-app in a disposable *_perf database.
use App\Modules\Inventory\Contracts\InventoryGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never { fwrite(STDERR, $error->getMessage()."\n"); exit(1); });
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_perf')) {
    throw new RuntimeException('Requires APP_ENV=testing and a disposable PostgreSQL database ending in _perf.');
}
if (DB::table('orders')->exists() || DB::table('users')->where('id', '>=', 10000)->exists()) {
    throw new RuntimeException('Use a fresh benchmark database; refusing to overwrite existing fixtures.');
}
$count = (int) ($argv[1] ?? 200000);
if ($count < 100000 || $count > 1000000) throw new RuntimeException('Choose 100000–1000000 orders.');
$start = microtime(true);
DB::transaction(function () use ($count): void {
    DB::insert("INSERT INTO categories (id,name,slug,created_at,updated_at) SELECT 1000+g, 'Performance category '||g, 'perf-'||g, now(),now() FROM generate_series(1,20) g");
    DB::insert("INSERT INTO products (id,title,description,price,category_id,created_at,updated_at) SELECT 10000+g, CASE WHEN g%25=0 THEN 'Performance phone ' ELSE 'Performance product ' END||g, 'Synthetic benchmark product', 19.99, 1001+(g%20),now()-(g||' seconds')::interval,now() FROM generate_series(1,500) g");
    DB::insert("INSERT INTO users (id,username,email,password,role,created_at,updated_at) SELECT 10000+g, 'perf-'||g, 'perf-'||g||'@example.test', ?, 'customer',now(),now() FROM generate_series(1,1000) g", [Hash::make('PerformanceOnly123!')]);
    DB::insert("INSERT INTO orders (id,user_id,total_amount,status,created_at,updated_at) SELECT 100000+g, 10001+(g%1000),59.97, CASE WHEN g%20=0 THEN 'pending' WHEN g%5=0 THEN 'cancelled' ELSE 'completed' END, now()-(g||' minutes')::interval,now()-(g||' minutes')::interval FROM generate_series(1,?) g", [$count]);
    DB::insert("INSERT INTO order_items (order_id,product_id,quantity,unit_price,created_at,updated_at) SELECT 100000+g,10001+((g*37+n*13)%500),1,19.99,now()-(g||' minutes')::interval,now()-(g||' minutes')::interval FROM generate_series(1,?) g CROSS JOIN generate_series(1,3) n", [$count]);
    foreach (['categories','products','users','orders','order_items'] as $table) {
        DB::select("SELECT setval(pg_get_serial_sequence('{$table}','id'), (SELECT max(id) FROM {$table}))");
    }
});
$inventory = app(InventoryGateway::class);
foreach (range(10001,10500) as $id) $inventory->setStock($id,10000);
foreach (['categories','products','users','orders','order_items'] as $table) DB::statement("ANALYZE {$table}");
echo json_encode(['orders'=>$count,'order_items'=>$count*3,'users'=>1000,'products'=>500,'seconds'=>round(microtime(true)-$start,2)], JSON_PRETTY_PRINT)."\n";
