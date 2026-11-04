<?php

namespace Database\Factories;

use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\CartItem;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * @var class-string<CartItem>
     */
    protected $model = CartItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 4),
        ];
    }
}
