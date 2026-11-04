<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order really moved to a new status (re-submitting the current status
 * does not dispatch it). Dispatched after the transaction committed.
 */
final class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $previousStatus,
    ) {}
}
