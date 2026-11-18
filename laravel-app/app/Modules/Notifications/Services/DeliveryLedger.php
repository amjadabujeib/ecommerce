<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** SQL deduplication and short leases; no transaction surrounds external I/O. */
final class DeliveryLedger
{
    public function deliver(array $event, string $path, int $generation, callable $send): array
    {
        $identity = ['event_id' => $event['event_id'], 'handler' => $path, 'recipient_id' => $path === 'mail' ? $event['customer_id'] : 0];
        $claim = DB::transaction(function () use ($identity, $generation): array {
            DB::table('notification_deliveries')->insertOrIgnore($identity + ['created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('notification_deliveries')->useWritePdo()->where($identity)->lockForUpdate()->first();
            if ($row->state === 'succeeded' || $row->state === 'deadlettered' || $generation < $row->generation) {
                return ['action' => 'ack'];
            }
            if ($row->state === 'processing' && $row->leased_until !== null && now()->lessThan($row->leased_until)) {
                throw new RuntimeException('Delivery is leased by another consumer.');
            }
            if ($row->state === 'failed' && $generation === $row->generation) {
                return $this->failurePlan($row->attempts, $generation, $row->last_error, json_decode($row->failure_history ?? '[]', true));
            }
            if ($generation !== (int) $row->generation) {
                throw new RuntimeException('Unexpected delivery generation.');
            }
            $token = (string) Str::uuid();
            $attempts = $row->state === 'processing' ? $row->attempts : $row->attempts + 1;
            DB::table('notification_deliveries')->where($identity)->update([
                'state' => 'processing', 'attempts' => $attempts,
                'lease_token' => $token, 'leased_until' => now()->addSeconds(config('messaging.lease_seconds')),
                'updated_at' => now(),
            ]);

            return ['action' => 'process', 'token' => $token, 'attempts' => $attempts, 'failure_history' => json_decode($row->failure_history ?? '[]', true)];
        });
        if ($claim['action'] !== 'process') {
            return $claim;
        }
        $owned = fn () => DB::table('notification_deliveries')->where($identity)->where('lease_token', $claim['token']);
        try {
            $send();
        } catch (Throwable $error) {
            $history = array_slice([...$claim['failure_history'], [
                'attempt' => $claim['attempts'], 'generation' => $generation,
                'error_type' => get_class($error), 'failed_at' => now()->toIso8601String(),
            ]], -20);
            if (! $owned()->update(['state' => 'failed', 'lease_token' => null, 'leased_until' => null, 'last_error' => get_class($error), 'failure_history' => json_encode($history, JSON_THROW_ON_ERROR), 'updated_at' => now()])) {
                throw new RuntimeException('Delivery lease was lost.');
            }

            return $this->failurePlan($claim['attempts'], $generation, get_class($error), $history);
        }
        if (! $owned()->update(['state' => 'succeeded', 'lease_token' => null, 'leased_until' => null, 'accepted_at' => now(), 'last_error' => null, 'updated_at' => now()])) {
            throw new RuntimeException('Delivery lease was lost after processing.');
        }

        return ['action' => 'ack'];
    }

    private function failurePlan(int $attempts, int $generation, string $error, array $history): array
    {
        $delays = config('messaging.retry_delays');

        return isset($delays[$attempts - 1])
            ? ['action' => 'retry', 'generation' => $generation + 1, 'delay' => $delays[$attempts - 1], 'error_type' => $error, 'failure_history' => $history]
            : ['action' => 'dlq', 'generation' => $generation, 'error_type' => $error, 'failure_history' => $history];
    }

    public function prepareReplay(array $event, string $path, ?int $sourceGeneration = null): int
    {
        return DB::transaction(function () use ($event, $path, $sourceGeneration): int {
            $query = DB::table('notification_deliveries')->where([
                'event_id' => $event['event_id'], 'handler' => $path,
                'recipient_id' => $path === 'mail' ? $event['customer_id'] : 0,
            ]);
            $row = (clone $query)->useWritePdo()->lockForUpdate()->first();
            // A broker delivery-limit DLQ can precede ledger creation/terminal marking.
            // Only the explicit replay command, holding that physical DLQ copy,
            // supplies its generation and may recover these interrupted states.
            if ($row === null && $sourceGeneration === 0) {
                DB::table('notification_deliveries')->insertOrIgnore([
                    'event_id' => $event['event_id'], 'handler' => $path,
                    'recipient_id' => $path === 'mail' ? $event['customer_id'] : 0,
                    'state' => 'deadlettered', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = (clone $query)->useWritePdo()->lockForUpdate()->first();
            }
            $interrupted = $row !== null && $sourceGeneration !== null && $sourceGeneration === (int) $row->generation
                && in_array($row->state, ['pending', 'waiting', 'failed', 'processing'], true)
                && ($row->leased_until === null || now()->greaterThanOrEqualTo($row->leased_until));
            if ($row === null || (! $interrupted && ! in_array($row->state, ['deadlettered', 'replay_pending'], true))) {
                throw new RuntimeException('Replay requires a terminal delivery that has not already succeeded.');
            }
            if ($row->state === 'replay_pending') {
                return (int) $row->generation;
            }
            $generation = $row->generation + 1;
            $query->update(['state' => 'replay_pending', 'generation' => $generation, 'attempts' => 0, 'updated_at' => now()]);

            return $generation;
        });
    }

    public function replayPublished(array $event, string $path, int $generation): void
    {
        DB::table('notification_deliveries')->where([
            'event_id' => $event['event_id'], 'handler' => $path,
            'recipient_id' => $path === 'mail' ? $event['customer_id'] : 0,
            'state' => 'replay_pending', 'generation' => $generation,
        ])->update(['state' => 'waiting', 'updated_at' => now()]);
    }

    /** Called only after RabbitMQ confirms durable handoff. */
    public function transferred(array $event, string $path, int $sourceGeneration, array $plan): void
    {
        DB::table('notification_deliveries')->where([
            'event_id' => $event['event_id'], 'handler' => $path,
            'recipient_id' => $path === 'mail' ? $event['customer_id'] : 0,
            'generation' => $sourceGeneration, 'state' => 'failed',
        ])->update([
            'state' => $plan['action'] === 'dlq' ? 'deadlettered' : 'waiting',
            'generation' => $plan['generation'], 'updated_at' => now(),
        ]);
    }
}
