<?php

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\CartItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

final class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_routes_are_versioned(): void
    {
        $this->assertSame('/api/v1', parse_url(route('home'), PHP_URL_PATH));

        $this->getJson('/api/v1')->assertOk();
        $this->getJson('/orders')->assertNotFound();
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $order = Order::factory()->create();
        $intruder = User::factory()->create();

        $this->withToken(JWTAuth::fromUser($intruder))
            ->getJson(route('orders.show', $order))
            ->assertForbidden()
            ->assertExactJson([
                'status' => 403,
                'code' => 'forbidden',
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_customer_order_list_only_contains_their_own_orders(): void
    {
        $customer = User::factory()->create();
        $own = Order::factory()->for($customer)->create();
        Order::factory()->count(2)->create();

        $this->withToken(JWTAuth::fromUser($customer))
            ->getJson(route('orders.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_customer_cannot_change_an_order_status(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer)->create();

        $this->withToken(JWTAuth::fromUser($customer))
            ->patchJson(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_customer_cannot_create_products(): void
    {
        $customer = User::factory()->create();

        $this->withToken(JWTAuth::fromUser($customer))
            ->postJson(route('admin.products.store'), [
                'title' => 'Not allowed',
                'description' => 'Customers cannot add products.',
                'price' => '1.00',
                'stock_quantity' => 1,
                'category_id' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_admin_can_change_an_order_status_with_a_token(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create();

        $this->withToken(JWTAuth::fromUser($admin))
            ->patchJson(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->getJson(route('orders.index'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $this->withToken('not.a.valid-token')
            ->getJson(route('orders.index'))
            ->assertUnauthorized();
    }

    public function test_tokens_expire_after_the_configured_ttl(): void
    {
        $token = JWTAuth::fromUser(User::factory()->create());

        $this->travel(config('jwt.ttl') + 1)->minutes();

        $this->withToken($token)
            ->getJson(route('me'))
            ->assertUnauthorized();
    }

    public function test_login_reports_the_token_lifetime(): void
    {
        $customer = User::factory()->create(['password' => 'StrongPass123!']);

        $this->postJson(route('login'), [
            'email' => $customer->email,
            'password' => 'StrongPass123!',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('expires_in', config('jwt.ttl') * 60);
    }

    public function test_refresh_issues_a_new_token_and_revokes_the_old_one(): void
    {
        $customer = User::factory()->create();
        $oldToken = JWTAuth::fromUser($customer);

        $newToken = $this->withToken($oldToken)
            ->postJson(route('refresh'))
            ->assertOk()
            ->json('token');

        $this->assertNotSame($oldToken, $newToken);

        $this->startNewRequestCycle();

        $this->withToken($oldToken)
            ->getJson(route('me'))
            ->assertUnauthorized();

        $this->startNewRequestCycle();

        $this->withToken($newToken)
            ->getJson(route('me'))
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);
    }

    public function test_refreshing_an_invalid_token_is_rejected(): void
    {
        $this->withToken('not.a.valid-token')
            ->postJson(route('refresh'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_sign_in_is_rate_limited_per_account(): void
    {
        $attempt = fn (string $email) => $this->postJson(route('login'), [
            'email' => $email,
            'password' => 'wrong-password',
        ]);

        foreach (range(1, 6) as $ignored) {
            $attempt('victim@example.com')->assertUnprocessable();
        }

        $attempt('victim@example.com')
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_requests');

        // Another account from the same address is not locked out.
        $attempt('someone-else@example.com')->assertUnprocessable();
    }

    public function test_responses_carry_security_headers(): void
    {
        $this->getJson(route('home'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function test_errors_share_one_format(): void
    {
        $this->getJson(route('products.show', 999999))
            ->assertNotFound()
            ->assertExactJson([
                'status' => 404,
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
            ]);

        $this->postJson(route('register'), [])
            ->assertUnprocessable()
            ->assertJsonPath('status', 422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['username', 'email', 'password']);

        $customer = User::factory()->create();
        $product = Product::factory()->withStock(1)->create();
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for($product)
            ->create(['quantity' => 1]);
        $this->inventory->setStock($product->id, 0);

        $this->withToken(JWTAuth::fromUser($customer))
            ->postJson(route('orders.store'))
            ->assertConflict()
            ->assertJsonPath('status', 409)
            ->assertJsonPath('code', 'conflict')
            ->assertJsonMissingPath('errors');
    }

    /**
     * Requests in one test share the container; drop the guards and the JWT
     * instance (which caches the parsed token) so the next request authenticates from scratch.
     */
    private function startNewRequestCycle(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
    }
}
