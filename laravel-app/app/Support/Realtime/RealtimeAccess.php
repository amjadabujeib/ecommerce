<?php

namespace App\Support\Realtime;

use App\Modules\Orders\PublicApi\OrderAccess;
use App\Modules\Users\PublicApi\UserDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Manager;
use PHPOpenSourceSaver\JWTAuth\Token;
use Throwable;

/** Rechecks the JWT and authoritative permission before every private socket delivery. */
final class RealtimeAccess
{
    public function __construct(private readonly OrderAccess $orders, private readonly UserDirectory $users) {}

    public function permitted(int $userId, string $channel): bool
    {
        if ($channel === 'private-admin.orders') {
            return $this->users->isAdministrator($userId);
        }
        if (preg_match('/^private-orders\.([1-9][0-9]*)$/D', $channel, $matches)) {
            return $this->orders->ownedBy((int) $matches[1], $userId);
        }

        return false;
    }

    public function authorize(string $socket, string $channel, string $token, int $userId): array
    {
        $payload = app(Manager::class)->decode(new Token($token));
        abort_unless((int) $payload->get('sub') === $userId && $this->permitted($userId, $channel), 403);
        $secret = (string) config('broadcasting.connections.reverb.secret');
        $key = (string) config('broadcasting.connections.reverb.key');
        abort_if($secret === '' || $key === '', 503, 'Real-time credentials are not configured.');
        DB::table('realtime_subscriptions')->updateOrInsert(['id' => $this->id($socket, $channel)], [
            'socket_id' => $socket, 'channel' => $channel, 'user_id' => $userId,
            'credential' => Crypt::encryptString($token),
            'expires_at' => Carbon::createFromTimestamp((int) $payload->get('exp'), 'UTC'),
        ]);

        return ['auth' => $key.':'.hash_hmac('sha256', $socket.':'.$channel, $secret)];
    }

    public function allows(string $socket, string $channel): bool
    {
        try {
            $row = DB::table('realtime_subscriptions')->useWritePdo()->where('id', $this->id($socket, $channel))->first();
            if (! $row || now()->greaterThanOrEqualTo(Carbon::parse($row->expires_at, 'UTC'))) {
                return false;
            }
            $payload = app(Manager::class)->decode(new Token(Crypt::decryptString($row->credential)));

            return (int) $payload->get('sub') === (int) $row->user_id
                && $this->permitted((int) $row->user_id, $channel);
        } catch (Throwable) {
            return false; // Database/cache outages and invalid credentials fail closed.
        }
    }

    private function id(string $socket, string $channel): string
    {
        return hash('sha256', $socket.'|'.$channel);
    }
}
