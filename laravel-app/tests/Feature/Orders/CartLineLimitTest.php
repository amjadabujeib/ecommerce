<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Cart;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartLineLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_lines_cannot_exceed_rpc_limit_but_existing_lines_can_be_edited(): void
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(101)->withStock(3)->create();
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->createMany($products->take(100)->map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1])->all());
        $this->actingAs($user)->postJson(route('cart.store', $products->last()), ['quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->patchJson(route('cart.update', $products->last()), ['quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->patchJson(route('cart.update', $products->first()), ['quantity' => 2])->assertOk();
        $this->assertSame(100, $cart->items()->count());
    }

    public function test_preexisting_oversized_cart_is_rejected_without_inventory_side_effects(): void
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(101)->withStock(1)->create();
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->createMany($products->map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1])->all());
        $this->actingAs($user)->postJson(route('orders.store'))->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], $this->inventory->releaseCalls);
        $this->deleteJson(route('cart.destroy', $products->last()))->assertOk();
        $this->postJson(route('orders.store'))->assertCreated();
    }
}
