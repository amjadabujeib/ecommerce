<?php

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Services\CartService;
use App\Modules\Products\Http\Resources\ProductResource;

/**
 * Serializes the cart for JSON responses.
 */
final class CartPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(CartService $cart): array
    {
        $items = $cart->items();

        return [
            'items' => array_map(
                fn (array $line): array => [
                    'product' => (new ProductResource($line['product']))->resolve(),
                    'quantity' => $line['quantity'],
                    'line_total' => number_format($line['line_total'], 2, '.', ''),
                ],
                $items,
            ),
            'item_count' => array_sum(array_column($items, 'quantity')),
            'subtotal' => number_format($cart->subtotal(), 2, '.', ''),
            'currency' => config('store.currency'),
        ];
    }
}
