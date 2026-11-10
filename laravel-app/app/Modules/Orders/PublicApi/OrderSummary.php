<?php

namespace App\Modules\Orders\PublicApi;

/** Committed order facts for event consumers, independent of persistence. */
final readonly class OrderSummary
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $status,
        public string $statusLabel,
        public string $totalAmount,
    ) {}
}
