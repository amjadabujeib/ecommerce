<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CancellationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_failure_after_release_blocks_completion_and_is_recovered_once(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(1)->create();
        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 1])->assertOk();
        $id = $this->postJson(route('orders.store'))->assertCreated()->json('data.id');
        Order::updating(function (Order $order): void {
            if ($order->status->value === 'cancelled') {
                throw new \RuntimeException('Injected local failure');
            }
        });
        try {
            $this->actingAs($admin)->patchJson(route('admin.orders.status', $id), ['status' => 'cancelled'])->assertStatus(500);
        } finally {
            Order::flushEventListeners();
        }
        $this->assertSame(1, $this->inventory->stockOf($product));
        $this->assertDatabaseHas('order_cancellations', ['order_id' => $id, 'state' => 'pending']);
        $this->patchJson(route('admin.orders.status', $id), ['status' => 'completed'])->assertConflict();
        $this->artisan('orders:recover-cancellations')->assertSuccessful();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('order_cancellations', ['order_id' => $id, 'state' => 'completed']);
        $this->assertSame(1, $this->inventory->stockOf($product));
        $calls = count($this->inventory->releaseCalls);
        $this->artisan('orders:recover-cancellations')->assertSuccessful();
        $this->assertCount($calls, $this->inventory->releaseCalls);
    }

    public function test_inventory_outage_leaves_durable_intent_for_scheduler(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(2)->create();
        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 1])->assertOk();
        $id = $this->postJson(route('orders.store'))->assertCreated()->json('data.id');
        $this->inventory->goDown();
        $this->actingAs($admin)->patchJson(route('admin.orders.status', $id), ['status' => 'cancelled'])->assertServiceUnavailable();
        $this->patchJson(route('admin.orders.status', $id), ['status' => 'completed'])->assertConflict();
        $this->inventory->comeBack();
        $this->artisan('orders:recover-cancellations')->assertSuccessful();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'cancelled']);
        $this->assertSame(2, $this->inventory->stockOf($product));
    }

    public function test_release_timeout_after_remote_commit_is_reconciled_without_returning_stock_twice(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(1)->create();
        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 1])->assertOk();
        $id = $this->postJson(route('orders.store'))->assertCreated()->json('data.id');
        $this->inventory->timeOutAfterNextRelease();
        $this->actingAs($admin)->patchJson(route('admin.orders.status', $id), ['status' => 'cancelled'])->assertServiceUnavailable();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'pending']);
        $this->assertSame(1, $this->inventory->stockOf($product));
        $this->patchJson(route('admin.orders.status', $id), ['status' => 'completed'])->assertConflict();
        $this->artisan('orders:recover-cancellations')->assertSuccessful();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'cancelled']);
        $this->assertSame(1, $this->inventory->stockOf($product));
    }
}
