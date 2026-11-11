<?php
// Disposable benchmark database only. Capture query plans without framework guesses.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || !str_ends_with(config('database.connections.pgsql.database'), '_perf')) exit(1);
$queries = [
    'admin_orders' => 'SELECT orders.*, (SELECT count(*) FROM order_items WHERE orders.id=order_items.order_id) AS items_count FROM orders ORDER BY created_at DESC LIMIT 12',
    'customer_orders' => 'SELECT orders.*, (SELECT count(*) FROM order_items WHERE orders.id=order_items.order_id) AS items_count FROM orders WHERE user_id=10001 ORDER BY created_at DESC LIMIT 10',
    'order_lines' => 'SELECT * FROM order_items WHERE order_id IN (100001,100002,100003,100004,100005,100006,100007,100008,100009,100010,100011,100012)',
];
$out=[];
foreach($queries as $name=>$sql) {
    $row=(array)DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql)[0];
    $out[$name]=['sql'=>$sql,'plan'=>json_decode(array_values($row)[0],true)];
}
echo json_encode($out,JSON_PRETTY_PRINT)."\n";
