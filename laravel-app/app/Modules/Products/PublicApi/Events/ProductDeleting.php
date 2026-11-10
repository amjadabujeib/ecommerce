<?php

namespace App\Modules\Products\PublicApi\Events;

use App\Modules\Products\PublicApi\ProductHasPendingOrdersException;
use Illuminate\Foundation\Events\Dispatchable;

/** Synchronous deletion guard, dispatched while Products holds its row lock. */
final class ProductDeleting
{
    use Dispatchable;

    private bool $retain = false;

    public function __construct(public readonly int $productId, public readonly string $title) {}

    public function refuseForPendingOrders(int $count): void
    {
        throw new ProductHasPendingOrdersException($this->title, $count);
    }

    public function retainForHistory(): void
    {
        $this->retain = true;
    }

    public function retainsHistory(): bool
    {
        return $this->retain;
    }
}
