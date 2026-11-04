<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_creates_an_order_and_decrements_stock(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(10)->create([
            'price' => '25.50',
        ]);

        $this->actingAs($customer)
            ->postJson(route('cart.store', $product), ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.item_count', 2);

        // Client supplied amounts must be ignored; the server recomputes.
        $response = $this->postJson(route('orders.store'), [
            'total_amount' => '0.01',
            'unit_price' => '0.01',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '51.00')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '25.50')
            ->assertJsonPath('data.items.0.line_total', '51.00');

        $order = Order::query()->with('items')->firstOrFail();

        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame('51.00', $order->total_amount);
        $this->assertSame('25.50', $order->items->first()->unit_price);
        $this->assertSame(8, $this->inventory->stockOf($product));

        $this->getJson(route('cart.index'))
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);
    }

    public function test_checkout_fails_with_insufficient_stock_without_creating_an_order(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(5)->create([
            'price' => '10.00',
        ]);

        $this->actingAs($customer)
            ->postJson(route('cart.store', $product), ['quantity' => 3])
            ->assertOk();

        // Simulate another customer buying the remaining units.
        $this->inventory->setStock($product->id, 2);

        $response = $this->postJson(route('orders.store'));

        $response->assertStatus(409);
        $this->assertStringContainsString('Only 2', $response->json('message'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(2, $this->inventory->stockOf($product));

        // The cart is preserved so the customer can adjust the quantity.
        $this->getJson(route('cart.index'))
            ->assertOk()
            ->assertJsonPath('data.item_count', 3);
    }

    public function test_cart_rejects_quantities_above_stock(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(2)->create();

        $this->actingAs($customer)
            ->postJson(route('cart.store', $product), ['quantity' => 3])
            ->assertStatus(409);

        $this->getJson(route('cart.index'))
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->postJson(route('orders.store'))->assertUnauthorized();
    }

    public function test_checkout_with_an_empty_cart_is_rejected(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->postJson(route('orders.store'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your cart is empty.');
    }

    public function test_customer_only_sees_their_own_orders(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();

        $ownOrder = Order::factory()->for($customer)->create();
        Order::factory()->for($otherCustomer)->create();

        $this->actingAs($customer)
            ->getJson(route('orders.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownOrder->id);
    }
}
