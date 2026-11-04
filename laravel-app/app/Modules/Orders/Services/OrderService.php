<?php

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Exceptions\InvalidOrderTransitionException;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Owns order status transitions and the stock restoration that must happen
 * exactly once when an order is cancelled.
 */
final class OrderService
{
    public function __construct(private readonly InventoryGateway $inventory) {}

    public function updateStatus(Order $order, OrderStatus $newStatus): Order
    {
        $previousStatus = null;

        $updated = DB::transaction(function () use ($order, $newStatus, &$previousStatus): Order {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            // Re-submitting the current status is a harmless no-op. Because
            // cancelled is terminal, stock can never be restored twice.
            if ($lockedOrder->status === $newStatus) {
                return $lockedOrder;
            }

            if (! $lockedOrder->status->canTransitionTo($newStatus)) {
                throw new InvalidOrderTransitionException($lockedOrder->status, $newStatus);
            }

            if ($newStatus === OrderStatus::Cancelled) {
                $this->restoreStock($lockedOrder);
            }

            $previousStatus = $lockedOrder->status;
            $lockedOrder->update(['status' => $newStatus]);

            return $lockedOrder->refresh();
        });

        // Announce only after the transaction committed and only on a real change.
        if ($previousStatus !== null) {
            OrderStatusChanged::dispatch($updated, $previousStatus);
        }

        return $updated;
    }

    /**
     * Give the order's reservation back to the inventory service. Called while
     * the order row is locked, so two cancellations cannot race; and Release
     * is idempotent on the inventory side as well. If the inventory service is
     * unavailable the exception rolls the transaction back and the order keeps
     * its status, so the cancellation can simply be retried.
     */
    private function restoreStock(Order $order): void
    {
        if ($order->inventory_reservation_id !== null) {
            $this->inventory->release($order->inventory_reservation_id);
        }
    }
}
