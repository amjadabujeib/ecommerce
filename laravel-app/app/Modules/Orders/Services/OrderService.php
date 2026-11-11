<?php

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CancellationPendingException;
use App\Modules\Orders\Exceptions\InvalidOrderTransitionException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\PublicApi\Events\OrderStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owns order status transitions and the stock restoration that must happen
 * exactly once when an order is cancelled.
 */
final class OrderService
{
    public function __construct(private readonly InventoryGateway $inventory) {}

    public function updateStatus(Order $order, OrderStatus $newStatus): Order
    {
        if ($newStatus === OrderStatus::Cancelled) {
            // This intent commits BEFORE Release. A timeout or SQL failure can
            // never make the still-visible order eligible for completion.
            DB::transaction(function () use ($order): void {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                if ($locked->isCancelled()) {
                    return;
                }
                DB::table('order_cancellations')->insertOrIgnore([
                    'order_id' => $locked->id, 'previous_status' => $locked->status->value,
                    'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        }
        $previousStatus = null;

        $updated = DB::transaction(function () use ($order, $newStatus, &$previousStatus): Order {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            $intent = DB::table('order_cancellations')->where('order_id', $lockedOrder->id)->first();
            if ($intent?->state === 'pending' && $newStatus !== OrderStatus::Cancelled) {
                throw new CancellationPendingException;
            }

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
            if ($newStatus === OrderStatus::Cancelled) {
                DB::table('order_cancellations')->where('order_id', $lockedOrder->id)
                    ->update(['state' => 'completed', 'updated_at' => now()]);
            }

            return $lockedOrder->refresh();
        });

        // Announce only after the transaction committed and only on a real change.
        if ($previousStatus !== null) {
            OrderStatusChanged::dispatch($updated->summary(), $previousStatus->value);
        }

        return $updated;
    }

    /** Retry durable cancellation intents without changing public order statuses. */
    public function recoverCancellations(): int
    {
        $recovered = 0;
        DB::table('order_cancellations')->where('state', 'pending')->orderBy('order_id')
            ->chunkById(100, function ($intents) use (&$recovered): void {
                foreach ($intents as $intent) {
                    try {
                        $this->updateStatus(Order::query()->findOrFail($intent->order_id), OrderStatus::Cancelled);
                        $recovered++;
                    } catch (Throwable $error) {
                        Log::error('Order cancellation remains pending', ['order_id' => $intent->order_id, 'error' => $error->getMessage()]);
                    }
                }
            }, 'order_id');

        return $recovered;
    }

    /** Release is idempotent; the durable intent survives any ambiguous result. */
    private function restoreStock(Order $order): void
    {
        if ($order->inventory_reservation_id !== null) {
            $this->inventory->release($order->inventory_reservation_id);
        }
    }
}
