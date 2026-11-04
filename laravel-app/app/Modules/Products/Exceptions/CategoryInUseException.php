<?php

namespace App\Modules\Products\Exceptions;

use RuntimeException;

/**
 * A category still has products, so deleting it would orphan them.
 */
final class CategoryInUseException extends RuntimeException
{
    public static function withProducts(string $categoryName, int $products): self
    {
        return new self(sprintf(
            'The category "%s" still has %d product(s). Move or delete them first.',
            $categoryName,
            $products,
        ));
    }

    public static function withOrderHistory(string $categoryName): self
    {
        return new self(sprintf(
            'The category "%s" holds deleted products that past orders still refer to, so it cannot be deleted. You can rename it instead.',
            $categoryName,
        ));
    }
}
