<?php

namespace App\Modules\Products\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product was taken out of the catalogue. Other modules use this to drop
 * whatever they hold for it (cart lines, for example).
 */
final class ProductRemoved
{
    use Dispatchable;

    public function __construct(public readonly int $productId) {}
}
