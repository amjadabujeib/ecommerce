<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['performance.enabled' => true, 'performance.catalog_cache' => true, 'performance.cache_store' => 'array', 'performance.diagnostics' => true]);
    }

    public function test_catalog_changes_invalidate_cached_snapshots_and_etags(): void
    {
        $category = Category::factory()->create(['name' => 'Original category']);
        $product = Product::factory()->for($category)->withStock(5)->create(['title' => 'Original title']);
        $first = $this->getJson(route('products.show', $product))->assertOk()->assertHeader('X-Catalog-Cache', 'miss');
        $tag = $first->headers->get('ETag');
        $this->getJson(route('products.show', $product), ['If-None-Match' => $tag])->assertStatus(304)->assertHeader('X-Catalog-Cache', 'hit')->assertContent('');

        $product->update(['title' => 'Changed title']);
        $changed = $this->getJson(route('products.show', $product), ['If-None-Match' => $tag])->assertOk()->assertJsonPath('data.title', 'Changed title');
        $this->assertNotSame($tag, $changed->headers->get('ETag'));

        $category->update(['name' => 'Changed category']);
        $this->getJson(route('products.show', $product))->assertOk()->assertJsonPath('data.category.name', 'Changed category');
    }

    public function test_cached_catalog_still_reports_live_stock_and_changes_etag(): void
    {
        $product = Product::factory()->withStock(5)->create();
        $first = $this->getJson(route('products.show', $product))->assertOk()->assertJsonPath('data.stock_quantity', 5);
        $tag = $first->headers->get('ETag');
        $this->inventory->setStock($product->id, 2);
        $changed = $this->getJson(route('products.show', $product), ['If-None-Match' => $tag])->assertOk()->assertHeader('X-Catalog-Cache', 'hit')->assertJsonPath('data.stock_quantity', 2);
        $this->assertNotSame($tag, $changed->headers->get('ETag'));
    }

    public function test_listing_cache_expires_and_reflects_new_products(): void
    {
        Product::factory()->withStock(1)->create(['title' => 'First']);
        $this->getJson(route('home'))->assertOk()->assertHeader('X-Catalog-Cache', 'miss');
        $this->getJson(route('home'))->assertOk()->assertHeader('X-Catalog-Cache', 'hit');
        $this->travel(61)->seconds();
        $this->getJson(route('home'))->assertOk()->assertHeader('X-Catalog-Cache', 'miss');
        Product::factory()->withStock(1)->create(['title' => 'Second']);
        $this->getJson(route('home'))->assertOk()->assertHeader('X-Catalog-Cache', 'miss')->assertJsonCount(2, 'data');
    }
}
