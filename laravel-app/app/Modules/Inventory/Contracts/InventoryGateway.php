<?php

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\Data\ReservationResult;
use App\Modules\Inventory\Exceptions\InventoryUnavailableException;

/**
 * Everything the application may ask the inventory service. Stock is owned by
 * that service; the rest of the application only talks to it through here.
 *
 * Every method throws InventoryUnavailableException when the service is down,
 * too slow (deadline exceeded) or otherwise cannot answer.
 */
interface InventoryGateway
{
    /**
     * Units available per product. Unknown products count as 0.
     *
     * @param  list<int>  $productIds
     * @return array<int, int> units keyed by product id
     *
     * @throws InventoryUnavailableException
     */
    public function availability(array $productIds): array;

    /**
     * Reserve every line in one atomic step, or nothing.
     *
     * @param  string  $reservationId  a UUID chosen by the caller
     * @param  array<int, int>  $quantities  units keyed by product id
     *
     * @throws InventoryUnavailableException
     */
    public function reserve(string $reservationId, array $quantities): ReservationResult;

    /**
     * Give a reservation's units back. Safe to repeat.
     *
     * @return bool true when this call returned units to stock
     *
     * @throws InventoryUnavailableException
     */
    public function release(string $reservationId): bool;

    /**
     * Set a product's absolute stock and return it.
     *
     * @throws InventoryUnavailableException
     */
    public function setStock(int $productId, int $quantity): int;
}
