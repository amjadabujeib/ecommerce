<?php

namespace App\Modules\Orders\PublicApi;

use App\Modules\Orders\Models\Order;

/** Primary-backed ownership for private order channels. Exports no persistence objects. */
final class OrderAccess
{
    public function ownedBy(int $orderId, int $userId): bool
    {
        return Order::query()->useWritePdo()->whereKey($orderId)->where('user_id', $userId)->exists();
    }
}
