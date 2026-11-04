<?php

namespace Database\Factories;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @var class-string<Product>
     */
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'title' => Str::title(fake()->unique()->sentence(3)),
            'description' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 5, 1500),
            'image_path' => null,
        ];
    }

    /**
     * Stock lives in the inventory service, not in the products table, so a
     * created product gets its stock through the InventoryGateway (the fake
     * one in tests). withStock() runs after this and overrides it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Product $product): void {
            app(InventoryGateway::class)->setStock($product->id, fake()->numberBetween(10, 100));
        });
    }

    public function withStock(int $units): static
    {
        return $this->afterCreating(function (Product $product) use ($units): void {
            app(InventoryGateway::class)->setStock($product->id, $units);
        });
    }

    public function outOfStock(): static
    {
        return $this->withStock(0);
    }
}
