<?php

// Internal companion to verify-cache-routing.mjs; disposable test databases only.
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\CatalogCache;
use App\Modules\Products\Services\ProductService;
use App\Modules\Users\Models\User;
use App\Support\ReadRouter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || ! str_ends_with(config('database.connections.pgsql.database'), '_perf')) {
    throw new RuntimeException('Requires testing and an isolated *_perf database.');
}
function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
$mode = $argv[1] ?? '';
$router = app(ReadRouter::class);
if ($mode === 'fixture') {
    $category = Category::create(['name' => 'Routing fixtures', 'slug' => 'routing-fixtures']);
    $product = app(ProductService::class)->create(['title' => 'Routing product', 'description' => 'Disposable', 'price' => '10.00', 'category_id' => $category->id, 'stock_quantity' => 100]);
    $admin = User::create(['username' => 'routing-admin', 'email' => 'routing-admin@example.test', 'password' => 'DisposableTest123!']);
    $admin->forceFill(['role' => 'admin'])->save();
    $customer = User::create(['username' => 'routing-customer', 'email' => 'routing-customer@example.test', 'password' => 'DisposableTest123!']);
    $order = Order::create(['user_id' => $customer->id, 'total_amount' => '10.00', 'status' => 'pending']);
    $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00']);
    echo json_encode(['product' => $product->id, 'category' => $category->id, 'admin' => $admin->id, 'customer' => $customer->id, 'order' => $order->id]);
} elseif ($mode === 'benchmark-stage') {
    $stage = $argv[2];
    ensure(in_array($stage, ['baseline', 'indexes-control', 'replica-only', 'replica-cache'], true), 'Unknown stage.');
    $path = $app->getCachedConfigPath();
    ensure(is_file($path), 'Cache config before benchmarking.');
    $config = require $path;
    $config['performance']['enabled'] = true; // Keep HTTP ETags identical across all stages.
    $config['performance']['diagnostics'] = false;
    $config['performance']['replica'] = in_array($stage, ['replica-only', 'replica-cache'], true);
    $config['performance']['catalog_cache'] = $stage === 'replica-cache';
    file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL, LOCK_EX);
    echo "Selected {$stage}.\n";
} elseif ($mode === 'benchmark-admin') {
    User::forceCreate(['username' => 'benchmark-admin', 'email' => 'admin@example.com', 'password' => 'DisposableTest123!', 'role' => 'admin']);
    echo "PASS: disposable benchmark administrator created.\n";
} elseif ($mode === 'review-request') {
    // A fresh process per request also catches token revocation across workers.
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if (isset($input['token'])) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$input['token'];
    }
    $request = Request::create($input['path'], $input['method'], server: $server, content: json_encode($input['body'] ?? []));
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
} elseif ($mode === 'review-password') {
    User::query()->useWritePdo()->where('email', $argv[2])->firstOrFail()->update(['password' => 'ChangedReview123!']);
    echo "Password updated on primary.\n";
} elseif ($mode === 'routing') {
    $router->configure(fn () => null);
    $db = DB::connection();
    $db->forgetRecordModificationState();
    ensure((bool) DB::selectOne('SELECT pg_is_in_recovery() AS standby')->standby, 'Ordinary SELECT must use replica.');
    ensure($router->role() === 'replica', 'Diagnostic must record actual replica selection.');
    $router->onPrimary(fn () => ensure(! DB::selectOne('SELECT pg_is_in_recovery() AS standby')->standby, 'Explicit authoritative read must use primary.'));
    DB::transaction(function () {
        ensure(! DB::selectOne('SELECT pg_is_in_recovery() AS standby')->standby, 'Transaction read must use primary.');
    });
    DB::table('products')->lockForUpdate()->first();
    ensure($router->role() === 'primary', 'Locking query must select primary PDO.');
    $db->beginTransaction();
    DB::table('users')->where('id', 1)->update(['username' => 'rolled-back']);
    ensure(! $router->hasWrites(), 'Uncommitted mutation cannot count as committed.');
    $db->rollBack();
    ensure(! $router->hasWrites(), 'Rollback cannot publish a committed mutation.');
    DB::transaction(function () {
        DB::table('users')->where('id', 1)->update(['username' => 'committed-admin']);
    });
    ensure($router->hasWrites(), 'Committed mutation must be tracked.');
    ensure(! DB::selectOne('SELECT pg_is_in_recovery() AS standby')->standby, 'Read after write must stay primary.');
    $router->finish();
    $db->forgetRecordModificationState();
    $router->configure(fn () => null);
    ensure(! $router->hasWrites(), 'New request must not inherit committed state.');
    echo "PASS: automatic reads, writes, transactions, locking, rollback and request reset.\n";
} elseif ($mode === 'http-operations') {
    $token = auth('api')->login(User::query()->useWritePdo()->findOrFail(2));
    Route::post('/operation-read', fn () => response()->json([
        'standby' => (bool) DB::selectOne('SELECT pg_is_in_recovery() AS standby')->standby,
    ]));
    Route::get('/operation-write', function () {
        DB::table('users')->where('id', 2)->update(['username' => 'operation-writer']);

        return response()->json(['committed' => true], 500);
    })->middleware('auth:api');
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    Auth::forgetGuards();
    $request = Request::create('/operation-read', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    ensure($response->getStatusCode() === 200 && json_decode($response->getContent(), true)['standby'], 'A read in POST must use replica.');
    $kernel->terminate($request, $response);
    Auth::forgetGuards();
    $request = Request::create('/operation-write', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $response = $kernel->handle($request);
    ensure($response->getStatusCode() === 500 && json_decode($response->getContent(), true)['committed'], 'Test write must commit before error response.');
    $kernel->terminate($request, $response);
    ensure(User::query()->useWritePdo()->findOrFail(2)->read_fence !== null, 'Committed write needs fence even for GET/error response.');
    echo "PASS: POST reads use replica; GET commits save fences even with HTTP 500.\n";
} elseif ($mode === 'crash-invalidation') {
    $control = DB::connection('primary_control');
    $control->beginTransaction();
    $control->selectOne('SELECT pg_advisory_xact_lock(734210617::bigint)');
    Cache::store('catalog')->flush();
    // Simulate process loss before acknowledging deletion in PostgreSQL.
    exit(31);
} elseif ($mode === 'fill-race') {
    $router->configure(fn () => null);
    $productId = (int) $argv[2];
    $cache = app(CatalogCache::class);
    $value = $cache->remember('race', ['id' => $productId], function () use ($productId) {
        $title = Product::findOrFail($productId)->title;
        file_put_contents('/tmp/catalog-fill-ready', 'ready');
        $until = microtime(true) + 10;
        while (! is_file('/tmp/catalog-fill-release') && microtime(true) < $until) {
            usleep(10000);
        }
        ensure(is_file('/tmp/catalog-fill-release'), 'Race harness failed to release fill.');

        return ['title' => $title];
    });
    echo json_encode($value);
} elseif ($mode === 'drain') {
    ensure(app(CatalogCache::class)->drain(), 'Invalidation failed.');
    echo "PASS: invalidation drained.\n";
} elseif ($mode === 'lock-contention') {
    $router->configure(fn () => null);
    $value = app(CatalogCache::class)->remember('contention', ['id' => 1], fn () => ['title' => Product::findOrFail(1)->title]);
    ensure($value['title'] === 'After exclusive table lock', 'Table lock fallback must eventually read fresh data.');
    echo "PASS: table/advisory lock contention releases fill before primary fallback.\n";
} elseif ($mode === 'uncached-fresh') {
    config(['performance.catalog_cache' => false]);
    $router->configure(fn () => null);
    $value = app(CatalogCache::class)->remember('detail', ['id' => 1], fn () => ['title' => Product::findOrFail(1)->title]);
    ensure($value['title'] === 'Uncached during lag', 'Uncached catalog must also be fresh during lag.');
    ensure($router->role() === 'primary', 'Uncached lagged catalog must use primary.');
    echo "PASS: uncached catalog checks WAL freshness without versions.\n";
} elseif ($mode === 'replica-outage') {
    $router->configure(fn () => null);
    DB::connection()->forgetRecordModificationState();
    ensure(! DB::selectOne('SELECT pg_is_in_recovery() AS standby')->standby, 'Unavailable replica must fall back.');
    echo "PASS: unavailable replica uses primary.\n";
} elseif ($mode === 'fenced-read') {
    $fence = $argv[2];
    $router->configure(fn () => $fence);
    DB::connection()->forgetRecordModificationState();
    ensure(! DB::selectOne('SELECT pg_is_in_recovery() AS standby')->standby, 'Lagged fence must force primary.');
    echo "PASS: lagged fence uses primary.\n";
} else {
    throw new RuntimeException('Unknown mode.');
}
