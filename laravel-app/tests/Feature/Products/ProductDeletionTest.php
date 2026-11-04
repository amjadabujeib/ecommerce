<?php

namespace Tests\Feature\Products;

use App\Modules\Orders\Models\CartItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_in_a_pending_order_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['title' => 'Desk Lamp']);
        OrderItem::factory()->for(Order::factory())->for($product)->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.products.destroy', $product))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                '"Desk Lamp" cannot be deleted because it is in 1 pending order(s). Complete or cancel that order first.',
            );

        $this->assertNotSoftDeleted($product);
    }

    public function test_pending_order_blocks_deletion_even_next_to_finished_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        OrderItem::factory()->for(Order::factory()->completed())->for($product)->create();
        OrderItem::factory()->for(Order::factory())->for($product)->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.products.destroy', $product))
            ->assertConflict();

        $this->assertNotSoftDeleted($product);
    }

    public function test_product_only_in_finished_orders_is_removed_but_kept_for_their_history(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/lamp.png', 'image');

        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'title' => 'Desk Lamp',
            'image_path' => 'products/lamp.png',
        ]);
        $completedOrder = Order::factory()->completed()->create();
        OrderItem::factory()->for($completedOrder)->for($product)->create();
        OrderItem::factory()->for(Order::factory()->cancelled())->for($product)->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.products.destroy', $product))
            ->assertOk()
            ->assertJsonPath('message', 'Product "Desk Lamp" was deleted.');

        $this->assertSoftDeleted($product);
        // The order history still needs the picture.
        Storage::disk('public')->assertExists('products/lamp.png');

        // Gone from the catalogue and the admin list...
        $this->getJson(route('products.show', $product))->assertNotFound();
        $this->actingAs($admin)
            ->getJson(route('admin.products.index'))
            ->assertJsonCount(0, 'data');

        // ...but the completed order still shows what was bought.
        $this->actingAs($completedOrder->user)
            ->getJson(route('orders.show', $completedOrder))
            ->assertOk()
            ->assertJsonPath('data.items.0.product_title', 'Desk Lamp');
    }

    public function test_never_ordered_product_is_deleted_for_good(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.products.destroy', $product))
            ->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_deleted_product_is_removed_from_carts(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        OrderItem::factory()->for(Order::factory()->completed())->for($product)->create();
        $cartLine = CartItem::factory()->for($product)->create();

        $this->actingAs($admin)
            ->deleteJson(route('admin.products.destroy', $product))
            ->assertOk();

        $this->assertModelMissing($cartLine);
    }
}
