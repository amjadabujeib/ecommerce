<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer's cart was turned into an order. Dispatched after the order
 * transaction committed, so listeners always see the saved order.
 */
final class OrderPlaced
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
