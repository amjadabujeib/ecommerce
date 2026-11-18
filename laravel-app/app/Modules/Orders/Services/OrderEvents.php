<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Support\Messaging\Outbox;
use Illuminate\Support\Str;

/** Records immutable public facts on the same connection/transaction as the order. */
final class OrderEvents
{
    public function __construct(private readonly Outbox $outbox) {}

    public function record(Order $order, string $type, ?string $previousStatus = null): ?string
    {
        if (! config('messaging.enabled')) {
            return null;
        }
        $id = (string) Str::uuid();
        $snapshot = [
            'id' => $order->id, 'status' => $order->status->value,
            'status_label' => $order->status->label(), 'total_amount' => $order->total_amount,
            'revision' => (int) $order->revision,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
        if ($type === 'order.created') {
            $snapshot['items'] = $order->items->map(fn ($line): array => [
                'product_id' => $line->product_id, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price,
            ])->all();
        }
        $this->outbox->record([
            'schema_version' => 1, 'event_id' => $id, 'type' => $type,
            'occurred_at' => now()->toIso8601String(), 'order_id' => $order->id,
            'order_revision' => (int) $order->revision, 'customer_id' => $order->user_id,
            'order' => $snapshot, 'previous_status' => $previousStatus,
        ]);

        return $id;
    }

    public function publish(?string $eventId): void
    {
        if ($eventId !== null && config('messaging.publish_immediately', true)) {
            $this->outbox->publishPending($eventId);
        }
    }
}
