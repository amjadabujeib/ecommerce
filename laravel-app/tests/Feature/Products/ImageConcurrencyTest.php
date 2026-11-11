<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ImageConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacements_from_stale_bound_models_remove_each_superseded_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/original.png', 'original');
        $product = Product::factory()->create(['image_path' => 'products/original.png']);
        $first = Product::findOrFail($product->id);
        $second = Product::findOrFail($product->id);
        $service = app(ProductService::class);
        $uploaded = $service->update($first, ['image' => $this->fakePng()])->image_path;
        $latest = $service->update($second, ['image' => $this->fakePng()])->image_path;
        Storage::disk('public')->assertMissing($uploaded);
        $this->assertSame([$latest], Storage::disk('public')->allFiles('products'));
    }

    public function test_hard_delete_uses_the_current_image_instead_of_stale_route_attributes(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/original.png', 'original');
        $product = Product::factory()->create(['image_path' => 'products/original.png']);
        $stale = Product::findOrFail($product->id);
        $service = app(ProductService::class);
        $service->update($product, ['image' => $this->fakePng()]);
        $service->delete($stale);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }
}
