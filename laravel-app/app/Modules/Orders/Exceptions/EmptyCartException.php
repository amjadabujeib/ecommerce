<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

final class EmptyCartException extends RuntimeException
{
    public function __construct(string $message = 'Your cart is empty.')
    {
        parent::__construct($message);
    }
}
