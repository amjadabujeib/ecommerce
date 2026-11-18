<?php

namespace App\Support\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class Outbox
{
    public function __construct(private readonly MessageBroker $broker) {}

    public function record(array $event): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Order events must be recorded inside the order transaction.');
        }
        EventEnvelope::validate($event);
        DB::table('message_outbox')->insert([
            'event_id' => $event['event_id'], 'type' => $event['type'],
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A post-commit notification failure never changes an order's result. */
    public function publishPending(?string $eventId = null): int
    {
        if (! config('messaging.enabled')) {
            return 0;
        }
        $published = 0;
        try {
            for ($i = 0; $i < ($eventId === null ? 25 : 1); $i++) {
                $row = DB::transaction(function () use ($eventId): ?object {
                    $query = DB::table('message_outbox')->useWritePdo()->whereNull('published_at')
                        ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                        ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', now()));
                    if ($eventId !== null) {
                        $query->where('event_id', $eventId);
                    }
                    $row = $query->orderBy('created_at')->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)->first();
                    if ($row === null) {
                        return null;
                    }
                    $row->lease_token = (string) Str::uuid();
                    DB::table('message_outbox')->where('event_id', $row->event_id)->update([
                        'lease_token' => $row->lease_token, 'leased_until' => now()->addSeconds(30),
                        'attempts' => $row->attempts + 1, 'updated_at' => now(),
                    ]);

                    return $row;
                });
                if ($row === null) {
                    break;
                }
                $owned = fn () => DB::table('message_outbox')->where('event_id', $row->event_id)->where('lease_token', $row->lease_token);
                try {
                    $this->broker->publishEvent(json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR));
                    $published += $owned()->update(['published_at' => now(), 'leased_until' => null, 'lease_token' => null, 'last_error' => null, 'updated_at' => now()]);
                } catch (Throwable $error) {
                    $owned()->update(['leased_until' => null, 'lease_token' => null, 'next_attempt_at' => now()->addSeconds(min(60, 2 ** min(6, $row->attempts))), 'last_error' => get_class($error), 'updated_at' => now()]);
                    Log::warning('Order event publication remains pending.', ['event_id' => $row->event_id, 'error_type' => get_class($error)]);
                    break;
                }
            }
        } catch (Throwable $error) {
            Log::warning('Outbox recovery remains pending.', ['error_type' => get_class($error)]);
        }

        return $published;
    }
}
