<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

final class CancellationPendingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cancellation is being recovered. This order cannot change status until cancellation finishes.');
    }
}
