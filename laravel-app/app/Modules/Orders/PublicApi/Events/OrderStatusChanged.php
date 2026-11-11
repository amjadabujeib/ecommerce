<?php

namespace App\Modules\Orders\PublicApi\Events;

use App\Modules\Orders\PublicApi\OrderSummary;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order really moved to a new status (re-submitting the current status
 * does not dispatch it). Carries immutable values dispatched after the transaction committed.
 */
final class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly OrderSummary $order,
        public readonly string $previousStatus,
    ) {}
}
