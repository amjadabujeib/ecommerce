<?php

namespace Tests\Fakes;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Inventory\Data\ReservationResult;
use App\Modules\Inventory\Data\Shortage;
use App\Modules\Inventory\Exceptions\InventoryUnavailableException;
use App\Modules\Products\Models\Product;

/**
 * In-memory stand-in for the inventory service, with the same rules as the
 * real one (all-or-nothing reservations, idempotent release, release markers)
 * plus switches to simulate the service being down or timing out.
 *
 * The real service and its race-condition tests live in nodejs-inventory/ at the project root.
 */
final class FakeInventory implements InventoryGateway
{
    /** @var array<int, int> units keyed by product id */
    private array $stock = [];

    /** @var array<string, array{status: string, items: array<int, int>}> */
    private array $reservations = [];

    /** @var list<string> reservation ids passed to release(), in order */
    public array $releaseCalls = [];

    private bool $down = false;

    private bool $timeOutAfterNextReserve = false;

    public function availability(array $productIds): array
    {
        $this->ensureUp('GetStock');

        $levels = [];

        foreach ($productIds as $productId) {
            $levels[$productId] = $this->stock[$productId] ?? 0;
        }

        return $levels;
    }

    public function reserve(string $reservationId, array $quantities): ReservationResult
    {
        $this->ensureUp('Reserve');

        if (isset($this->reservations[$reservationId])) {
            return $this->reservations[$reservationId]['status'] === 'released'
                ? ReservationResult::alreadyReleased()
                : ReservationResult::reserved();
        }

        $shortages = [];

        foreach ($quantities as $productId => $quantity) {
            $available = $this->stock[$productId] ?? 0;

            if ($quantity > $available) {
                $shortages[] = new Shortage($productId, $quantity, $available);
            }
        }

        if ($shortages !== []) {
            return ReservationResult::rejected($shortages);
        }

        foreach ($quantities as $productId => $quantity) {
            $this->stock[$productId] -= $quantity;
        }

        $this->reservations[$reservationId] = ['status' => 'reserved', 'items' => $quantities];

        if ($this->timeOutAfterNextReserve) {
            // The service did the work, but the answer never reached us.
            $this->timeOutAfterNextReserve = false;

            throw new InventoryUnavailableException('Reserve: no answer within the deadline');
        }

        return ReservationResult::reserved();
    }

    public function release(string $reservationId): bool
    {
        $this->ensureUp('Release');
        $this->releaseCalls[] = $reservationId;

        $reservation = $this->reservations[$reservationId] ?? null;

        if ($reservation === null) {
            $this->reservations[$reservationId] = ['status' => 'released', 'items' => []];

            return false;
        }

        if ($reservation['status'] === 'released') {
            return false;
        }

        foreach ($reservation['items'] as $productId => $quantity) {
            $this->stock[$productId] = ($this->stock[$productId] ?? 0) + $quantity;
        }

        $this->reservations[$reservationId]['status'] = 'released';

        return true;
    }

    public function setStock(int $productId, int $quantity): int
    {
        $this->ensureUp('SetStock');

        return $this->stock[$productId] = $quantity;
    }

    public function stockOf(Product|int $product): int
    {
        return $this->stock[$product instanceof Product ? $product->id : $product] ?? 0;
    }

    /** Simulate a stopped (or too slow) inventory service. */
    public function goDown(): void
    {
        $this->down = true;
    }

    public function comeBack(): void
    {
        $this->down = false;
    }

    /** The next Reserve takes the stock, then the caller's deadline expires. */
    public function timeOutAfterNextReserve(): void
    {
        $this->timeOutAfterNextReserve = true;
    }

    private function ensureUp(string $method): void
    {
        if ($this->down) {
            throw new InventoryUnavailableException("{$method}: no answer within the deadline");
        }
    }
}
