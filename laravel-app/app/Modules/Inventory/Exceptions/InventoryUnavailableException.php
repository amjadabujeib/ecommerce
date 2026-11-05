<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The inventory service is down, did not answer before the deadline, or
 * failed. Rendered as 503 Service Unavailable (see bootstrap/app.php).
 */
final class InventoryUnavailableException extends RuntimeException
{
    public const string MESSAGE = 'The inventory service is not available right now. Please try again in a moment.';

    public function __construct(
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
