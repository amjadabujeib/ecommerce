<?php

namespace App\Modules\Products\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Str;
use RuntimeException;

/** A flush revokes every outstanding one-use fill permit, atomically with deletion. */
final class CatalogRedisStore extends RedisStore
{
    public function beginFill(): string
    {
        $permit = $this->prefix.'catalog:fill:'.Str::uuid();
        if (! $this->connection()->setex($permit, 60, '1')) {
            throw new RuntimeException('Cannot acquire catalog fill permit.');
        }

        return $permit;
    }

    public function publish(string $permit, string $key, array $value, int $seconds): bool
    {
        $connection = $this->connection();

        return (bool) $connection->eval(<<<'LUA'
if redis.call('exists', KEYS[1]) == 0 then
    return 0
end
redis.call('setex', KEYS[2], ARGV[2], ARGV[1])
redis.call('del', KEYS[1])
return 1
LUA, 2, $permit, $this->prefix.$key, $this->pack($value, $connection), max(1, $seconds));
    }

    public function abandon(string $permit): void
    {
        $this->connection()->del($permit);
    }
}
