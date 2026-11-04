<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

final class CartPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_cart_persists_between_token_requests_and_checks_out(): void
    {
        $customer = User::factory()->create();
        $token = JWTAuth::fromUser($customer);
        $product = Product::factory()->withStock(5)->create([
            'price' => '15.00',
        ]);

        $this->withToken($token)
            ->postJson(route('cart.store', $product), ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.item_count', 2);

        $this->withToken($token)
            ->getJson(route('cart.index'))
            ->assertOk()
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.subtotal', '30.00');

        $this->withToken($token)
            ->postJson(route('orders.store'))
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '30.00');

        $this->assertSame(3, $this->inventory->stockOf($product));
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame($customer->id, Order::query()->firstOrFail()->user_id);
    }

    public function test_api_cart_requires_a_token(): void
    {
        $product = Product::factory()->create();

        $this->postJson(route('cart.store', $product), ['quantity' => 1])
            ->assertUnauthorized();
    }

    public function test_api_logout_revokes_the_current_token(): void
    {
        $customer = User::factory()->create();
        $token = JWTAuth::fromUser($customer);

        $this->withToken($token)
            ->postJson(route('logout'))
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson(route('me'))
            ->assertUnauthorized();
    }
}
