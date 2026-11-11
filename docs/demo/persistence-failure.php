<?php

// Run inside laravel-app with APP_ENV=testing and disposable databases only:
// php ../docs/demo/persistence-failure.php
// In the app container, run as www-data to preserve log/storage ownership.
// Uses the real InventoryGateway; no test framework or inventory fake needed.

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
// Laravel's CLI exception renderer can otherwise hide a failing demo behind
// a successful process exit when this standalone script throws.
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    exit(1);
});

if (! $app->environment('testing')) {
    throw new RuntimeException('Use APP_ENV=testing and disposable databases.');
}

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$inventory = $app->make(InventoryGateway::class);
$runId = Str::uuid()->toString();
$category = Category::create(['name' => 'Compensation '.$runId]);
$product = $user = null;

try {
    $product = Product::create([
        'title' => 'Compensation '.$runId, 'description' => 'Disposable verification fixture',
        'price' => '2.50', 'category_id' => $category->id,
    ]);
    $inventory->setStock($product->id, 5);
    $user = User::create([
        'username' => 'compensation'.str_replace('-', '', $runId),
        'email' => $runId.'@example.com', 'password' => Str::random(32),
    ]);
    $cart = Cart::create(['user_id' => $user->id]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);
    $token = Auth::guard('api')->login($user);
    Auth::forgetGuards();

    $observedReservation = false;
    OrderItem::creating(function () use ($inventory, $product, &$observedReservation): never {
        // Proves that failure occurs after the remote reservation took stock.
        verify($inventory->availability([$product->id])[$product->id] === 3, 'Stock was not reserved before saving.');
        $observedReservation = true;
        throw new RuntimeException('Deliberate order-line persistence failure');
    });

    $request = Request::create('/api/v1/orders', 'POST', server: [
        'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/json',
    ], content: '{}');
    $response = $kernel->handle($request);
    verify($observedReservation, 'The injected persistence failure was not reached.');
    verify($response->getStatusCode() === 500, 'Expected a structured HTTP 500.');
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    verify($body['status'] === 500 && $body['code'] === 'server_error', 'Unexpected failure envelope.');
    verify(! isset($body['trace']), 'Failure exposed a trace.');
    verify(! Order::where('user_id', $user->id)->exists(), 'An order survived the local rollback.');
    verify($inventory->availability([$product->id])[$product->id] === 5, 'Compensation did not restore stock.');
    verify($cart->items()->count() === 1, 'Failed checkout lost the cart.');
    $kernel->terminate($request, $response);
    echo "PASS: remote stock reserved, local order-line save failed, order rolled back, stock restored, cart preserved.\n";
} finally {
    OrderItem::flushEventListeners();
    $user?->delete();
    if ($product !== null) {
        $inventory->setStock($product->id, 0);
        $product->forceDelete();
    }
    $category->delete();
}
