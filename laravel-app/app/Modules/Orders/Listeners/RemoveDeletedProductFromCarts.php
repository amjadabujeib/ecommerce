<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Models\CartItem;
use App\Modules\Products\Events\ProductRemoved;

/**
 * Cart lines are not a historical record: when a product leaves the
 * catalogue it disappears from every cart, so it no longer counts towards the
 * cart badge or blocks checkout.
 */
final class RemoveDeletedProductFromCarts
{
    public function handle(ProductRemoved $event): void
    {
        CartItem::query()->where('product_id', $event->productId)->delete();
    }
}
