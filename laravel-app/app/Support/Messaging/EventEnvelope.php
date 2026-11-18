<?php

namespace App\Support\Messaging;

use InvalidArgumentException;

final class EventEnvelope
{
    public static function validate(array $event): array
    {
        if (($event['schema_version'] ?? null) !== 1
            || ! in_array($event['type'] ?? null, ['order.created', 'order.status_changed'], true)
            || ! is_string($event['event_id'] ?? null)
            || ! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $event['event_id'])
            || ! is_int($event['order_id'] ?? null) || $event['order_id'] < 1
            || ! is_int($event['customer_id'] ?? null) || $event['customer_id'] < 1
            || ! is_int($event['order_revision'] ?? null) || $event['order_revision'] < 1
            || ! is_string($event['occurred_at'] ?? null)
            || ! is_array($event['order'] ?? null)
            || ($event['order']['id'] ?? null) !== $event['order_id']
            || ! in_array($event['order']['status'] ?? null, ['pending', 'completed', 'cancelled'], true)
            || ! is_string($event['order']['status_label'] ?? null)
            || ! is_string($event['order']['total_amount'] ?? null)
            || ! preg_match('/^\d{1,8}\.\d{2}$/', $event['order']['total_amount'])
            || ($event['type'] === 'order.status_changed' && ! in_array($event['previous_status'] ?? null, ['pending', 'completed', 'cancelled'], true))) {
            throw new InvalidArgumentException('Unsupported or malformed order event.');
        }
        $items = $event['order']['items'] ?? [];
        if (! is_array($items) || count($items) > 100 || ($event['type'] === 'order.created' && $items === [])) {
            throw new InvalidArgumentException('Malformed committed order lines.');
        }
        foreach ($items as $line) {
            if (! is_array($line) || ! is_int($line['product_id'] ?? null) || ($line['product_id'] ?? 0) < 1 || ! is_int($line['quantity'] ?? null)
                || $line['quantity'] < 1 || ! is_string($line['unit_price'] ?? null)
                || ! preg_match('/^\d{1,8}\.\d{2}$/', $line['unit_price'])) {
                throw new InvalidArgumentException('Malformed committed order line.');
            }
        }

        return $event;
    }

    public static function stableId(string $key): string
    {
        $hash = hash('sha256', $key);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }
}
