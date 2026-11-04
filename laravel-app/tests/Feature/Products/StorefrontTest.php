<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_lists_products_with_categories(): void
    {
        $product = Product::factory()->create(['title' => 'Wireless Headphones']);
        Product::factory()->create(['title' => 'Wired Mouse']);

        $this->getJson(route('home'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category.id', $product->category_id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'price',
                        'stock_quantity',
                        'in_stock',
                        'category' => ['id', 'name', 'slug'],
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    public function test_products_can_be_searched_by_title(): void
    {
        $headphones = Product::factory()->create(['title' => 'Wireless Headphones']);
        Product::factory()->create(['title' => 'Wired Mouse']);

        $this->getJson(route('home', ['q' => 'Headphones']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $headphones->id);
    }

    public function test_products_can_be_filtered_by_category(): void
    {
        $electronics = Category::factory()->create(['name' => 'Electronics']);
        $kitchen = Category::factory()->create(['name' => 'Kitchen']);

        $monitor = Product::factory()->for($electronics)->create();
        Product::factory()->for($kitchen)->create();

        $this->getJson(route('home', ['category' => $electronics->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $monitor->id);
    }

    public function test_product_detail_returns_the_product_and_related_items(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $related = Product::factory()->for($category)->create();

        $this->getJson(route('products.show', $product))
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonCount(1, 'related')
            ->assertJsonPath('related.0.id', $related->id);
    }

    public function test_unknown_product_returns_404(): void
    {
        $this->getJson(route('products.show', 999999))->assertNotFound();
    }
}
