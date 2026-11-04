<?php

namespace App\Modules\Products\Exceptions;

use RuntimeException;

final class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly string $productTitle,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(sprintf(
            'Only %d unit(s) of "%s" are available; you requested %d.',
            $available,
            $productTitle,
            $requested,
        ));
    }
}
