<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ReservationRecovery;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReservationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function abandoned(): array
    {
        $product = Product::factory()->withStock(5)->create();
        $id = (string) Str::uuid();
        app(ReservationRecovery::class)->begin($id);
        $this->inventory->reserve($id, [$product->id => 2]);
        DB::table('checkout_reservations')->where('id', $id)->update(['created_at' => now()->subMinutes(6)]);
        return [$id, $product];
    }

    public function test_recovers_stock_after_process_dies_before_order_persistence(): void
    {
        [$id, $product] = $this->abandoned();
        $this->artisan('orders:recover-reservations')->assertSuccessful();
        $this->assertSame(5, $this->inventory->stockOf($product));
        $this->assertDatabaseHas('checkout_reservations', ['id' => $id, 'state' => 'released']);
        $this->artisan('orders:recover-reservations')->assertSuccessful();
        $this->assertCount(1, $this->inventory->releaseCalls);
    }

    public function test_failed_release_remains_durable_and_recovers_when_inventory_returns(): void
    {
        [$id, $product] = $this->abandoned();
        $this->inventory->goDown();
        $this->assertFalse(app(ReservationRecovery::class)->release($id));
        $this->assertDatabaseHas('checkout_reservations', ['id' => $id, 'state' => 'pending']);
        $this->inventory->comeBack();
        $this->artisan('orders:recover-reservations')->assertSuccessful();
        $this->assertSame(5, $this->inventory->stockOf($product));
    }

    public function test_existing_order_protects_stock_even_if_commit_result_was_ambiguous(): void
    {
        [$id, $product] = $this->abandoned();
        Order::factory()->create(['inventory_reservation_id' => $id]);
        $this->artisan('orders:recover-reservations')->assertSuccessful();
        $this->assertSame(3, $this->inventory->stockOf($product));
        $this->assertDatabaseHas('checkout_reservations', ['id' => $id, 'state' => 'committed']);
    }

    public function test_checkout_timeout_after_remote_commit_releases_the_same_intent(): void
    {
        $customer = \App\Modules\Users\Models\User::factory()->create();
        $product = Product::factory()->withStock(5)->create();
        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();
        $this->inventory->timeOutAfterNextReserve();
        $this->postJson(route('orders.store'))->assertServiceUnavailable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $this->inventory->stockOf($product));
        $this->assertDatabaseHas('checkout_reservations', ['state' => 'released']);
        $this->getJson(route('cart.index'))->assertJsonPath('data.item_count', 2);
    }

    public function test_checkout_persistence_failure_with_failed_compensation_is_recoverable(): void
    {
        $customer = \App\Modules\Users\Models\User::factory()->create();
        $product = Product::factory()->withStock(5)->create();
        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();
        \App\Modules\Orders\Models\OrderItem::creating(function (): never {
            $this->inventory->goDown();
            throw new \RuntimeException('Deliberate order-line failure');
        });
        try {
            $this->postJson(route('orders.store'))->assertStatus(500);
        } finally {
            \App\Modules\Orders\Models\OrderItem::flushEventListeners();
            $this->inventory->comeBack();
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(3, $this->inventory->stockOf($product));
        $this->assertDatabaseHas('checkout_reservations', ['state' => 'pending']);
        $this->travel(6)->minutes();
        $this->artisan('orders:recover-reservations')->assertSuccessful();
        $this->assertSame(5, $this->inventory->stockOf($product));
    }

    public function test_recovery_does_not_claim_recent_checkout_intents(): void
    {
        $id = (string) Str::uuid();
        app(ReservationRecovery::class)->begin($id);
        $this->artisan('orders:recover-reservations')->assertSuccessful();
        $this->assertSame([], $this->inventory->releaseCalls);
    }
}
