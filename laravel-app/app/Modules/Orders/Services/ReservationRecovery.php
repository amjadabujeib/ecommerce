<?php

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Contracts\InventoryGateway;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Durable checkout intents survive process death and failed compensation. */
final class ReservationRecovery
{
    public function __construct(private readonly InventoryGateway $inventory) {}

    public function begin(string $id): void
    {
        DB::table('checkout_reservations')->insert([
            'id' => $id, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Called inside the same transaction as Reserve and order persistence. */
    public function lock(string $id): void
    {
        $intent = DB::table('checkout_reservations')->where('id', $id)->lockForUpdate()->first();
        if ($intent === null || $intent->state !== 'pending') {
            throw new \RuntimeException('The checkout reservation has already been recovered. Please retry checkout.');
        }
    }

    public function committed(string $id): void
    {
        DB::table('checkout_reservations')->where('id', $id)->update(['state' => 'committed', 'updated_at' => now()]);
    }

    /** Also checks the order after an ambiguous database commit response. */
    public function release(string $id, bool $remoteMayHaveCommitted = true): bool
    {
        try {
            return DB::transaction(function () use ($id, $remoteMayHaveCommitted): bool {
                $intent = DB::table('checkout_reservations')->where('id', $id)->lockForUpdate()->first();
                if ($intent === null || $intent->state !== 'pending') {
                    return false;
                }
                if (Order::query()->where('inventory_reservation_id', $id)->exists()) {
                    $this->committed($id);

                    return false;
                }
                if ($remoteMayHaveCommitted) {
                    $this->inventory->release($id);
                }
                DB::table('checkout_reservations')->where('id', $id)->update(['state' => 'released', 'updated_at' => now()]);

                return true;
            });
        } catch (Throwable $exception) {
            Log::error('Reservation recovery pending; retry with orders:recover-reservations', [
                'reservation_id' => $id, 'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** Age protects new requests; the row lock serializes recovery with checkout. */
    public function recover(): int
    {
        $recovered = 0;
        DB::table('checkout_reservations')->where('state', 'pending')
            ->where('created_at', '<=', now()->subMinutes(5))->orderBy('id')
            ->chunkById(100, function ($intents) use (&$recovered): void {
                foreach ($intents as $intent) {
                    $recovered += (int) $this->release($intent->id);
                }
            });

        return $recovered;
    }
}
