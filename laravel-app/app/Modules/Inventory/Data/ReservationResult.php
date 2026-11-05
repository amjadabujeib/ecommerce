<?php

namespace App\Modules\Inventory\Data;

/**
 * The inventory's answer to a reservation. Not enough stock is a normal
 * answer (rejected, with the shortages), not an error.
 */
final readonly class ReservationResult
{
    /**
     * @param  list<Shortage>  $shortages
     */
    private function __construct(
        public ReservationStatus $status,
        public array $shortages = [],
    ) {}

    public static function reserved(): self
    {
        return new self(ReservationStatus::Reserved);
    }

    /**
     * @param  list<Shortage>  $shortages
     */
    public static function rejected(array $shortages): self
    {
        return new self(ReservationStatus::Rejected, $shortages);
    }

    public static function alreadyReleased(): self
    {
        return new self(ReservationStatus::Released);
    }

    public function isReserved(): bool
    {
        return $this->status === ReservationStatus::Reserved;
    }
}
