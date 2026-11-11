<?php

namespace App\Modules\Orders\PublicApi\Events;

use App\Modules\Orders\PublicApi\OrderSummary;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer's cart was turned into an order. Dispatched after the order
 * transaction committed, so listeners receive a snapshot of the committed order.
 */
final class OrderPlaced
{
    use Dispatchable;

    public function __construct(public readonly OrderSummary $order) {}
}
