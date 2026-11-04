<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\CartItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_an_order_restores_stock_exactly_once(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(10)->create([
            'price' => '12.00',
        ]);

        $order = $this->placeOrder($customer, $product, 4);

        $this->assertSame(6, $this->inventory->stockOf($product));

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath(
                'message',
                "Order #{$order->id} was cancelled and stock was restored.",
            );

        $this->assertSame(10, $this->inventory->stockOf($product));

        // Submitting "cancelled" again must not restore stock a second time.
        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('message', "Order #{$order->id} is already Cancelled.");

        $this->assertSame(10, $this->inventory->stockOf($product));
        $this->assertSame('cancelled', $order->fresh()->status->value);
    }

    public function test_cancelled_is_a_terminal_status(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $order = $this->placeOrder($customer, $product, 1);

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'An order cannot change from "Cancelled" to "Completed".',
            );

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame(10, $this->inventory->stockOf($product));
    }

    public function test_completed_order_can_still_be_cancelled_and_restores_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(10)->create();

        $order = $this->placeOrder($customer, $product, 2);

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(8, $this->inventory->stockOf($product));

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame(10, $this->inventory->stockOf($product));
    }

    public function test_invalid_status_values_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create();

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'shipped'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_product_in_a_pending_order_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(5)->create();

        $this->placeOrder($customer, $product, 1);

        $this->actingAs($admin)
            ->deleteJson(route('admin.products.destroy', $product))
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    private function placeOrder(User $customer, Product $product, int $quantity): Order
    {
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for($product)
            ->create(['quantity' => $quantity]);

        $this->actingAs($customer)
            ->postJson(route('orders.store'))
            ->assertCreated();

        return Order::query()->latest('id')->firstOrFail();
    }
}
