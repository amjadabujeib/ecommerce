<?php

namespace App\Modules\Products\Exceptions;

use RuntimeException;

/**
 * Thrown when an administrator tries to delete a product that is still part
 * of an order waiting to be handled. Such an order must be completed or
 * cancelled first.
 */
final class ProductHasPendingOrdersException extends RuntimeException
{
    public function __construct(
        public readonly string $productTitle,
        public readonly int $pendingOrders,
    ) {
        parent::__construct(sprintf(
            '"%s" cannot be deleted because it is in %d pending order(s). Complete or cancel %s first.',
            $productTitle,
            $pendingOrders,
            $pendingOrders === 1 ? 'that order' : 'those orders',
        ));
    }
}
