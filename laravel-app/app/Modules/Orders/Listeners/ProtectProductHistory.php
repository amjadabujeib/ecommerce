<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Products\PublicApi\Events\ProductDeleting;

/** Orders evaluates its own data; Products owns the deletion decision. */
final class ProtectProductHistory
{
    public function handle(ProductDeleting $event): void
    {
        $pending = Order::query()->where('status', OrderStatus::Pending)
            ->whereHas('items', fn ($query) => $query->where('product_id', $event->productId))->count();
        if ($pending > 0) {
            $event->refuseForPendingOrders($pending);
        }
        if (OrderItem::query()->where('product_id', $event->productId)->exists()) {
            $event->retainForHistory();
        }
    }
}
