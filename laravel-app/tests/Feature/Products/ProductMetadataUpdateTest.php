<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductMetadataUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_update_preserves_live_stock_and_explicit_adjustment_still_works(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->withStock(10)->create();
        $this->inventory->setStock($product->id, 8);
        $attributes = ['title' => 'Changed title', 'description' => 'Changed', 'price' => '3.00', 'category_id' => $product->category_id];
        $this->actingAs($admin)->putJson(route('admin.products.update', $product), $attributes)
            ->assertOk()->assertJsonPath('data.stock_quantity', 8);
        $this->assertSame(8, $this->inventory->stockOf($product));
        $this->putJson(route('admin.products.update', $product), $attributes + ['stock_quantity' => 12])
            ->assertOk()->assertJsonPath('data.stock_quantity', 12);
    }
}
