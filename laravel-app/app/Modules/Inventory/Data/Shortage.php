<?php

namespace App\Modules\Inventory\Data;

final readonly class Shortage
{
    public function __construct(
        public int $productId,
        public int $requested,
        public int $available,
    ) {}
}
