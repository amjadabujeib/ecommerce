<?php

namespace Tests\Feature\Orders;

use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderTotalLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_oversized_total_is_rejected_before_reservation_and_preserves_cart(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->withStock(2)->create(['price' => '60000000.00']);
        $this->actingAs($user)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();
        $this->postJson(route('orders.store'))->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], $this->inventory->releaseCalls);
        $this->assertSame(2, $this->inventory->stockOf($product));
        $this->getJson(route('cart.index'))->assertJsonPath('data.item_count', 2);
    }

    public function test_largest_representable_total_can_be_ordered(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->withStock(1)->create(['price' => '99999999.99']);
        $this->actingAs($user)->postJson(route('cart.store', $product), ['quantity' => 1])->assertOk();
        $this->postJson(route('orders.store'))->assertCreated()->assertJsonPath('data.total_amount', '99999999.99');
    }
}
