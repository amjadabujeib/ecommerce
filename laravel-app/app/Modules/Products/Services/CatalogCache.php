<?php

namespace App\Modules\Products\Services;

use App\Support\ReadRouter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Stable keys, physical deletion, and durable recovery of unfinished invalidation. */
final class CatalogCache
{
    // Also used by the product/category triggers in the replacement migration.
    private const int LOCK_ID = 734210617;

    public string $status = 'disabled';

    public function __construct(private readonly ReadRouter $reads) {}

    public function remember(string $kind, array $parameters, callable $load): array
    {
        if (! config('performance.catalog_cache')) {
            $this->status = 'disabled';

            return $this->loadFresh($load);
        }
        // Never acquire a second connection's lock while our own transaction may
        // already hold the exclusive writer lock. Transactional reads stay primary.
        if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() > 0) {
            $this->status = 'bypass';

            return $this->reads->onPrimary($load);
        }
        $key = 'catalog:'.$kind.':'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
        try {
            $store = $this->store();
            // A writer can race between draining and entering the shared section.
            // Retry once, then use an uncached primary read rather than wait forever.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $result = $this->coordinated(false, function (Connection $control) use ($store, $key, $load): ?array {
                    if ($this->dirty($control)) {
                        return null;
                    }
                    $cached = $store->get($key);
                    if (is_array($cached)) {
                        $this->status = 'hit';

                        return $cached;
                    }
                    // Issued before loading data. Invalidation deletes permits as well
                    // as snapshots, so even a fill whose PostgreSQL lock disappears
                    // cannot publish after another process has flushed the cache.
                    $redis = $store->getStore();
                    $permit = $redis instanceof CatalogRedisStore ? $redis->beginFill() : null;
                    try {
                        $value = $this->boundedFill(fn () => $this->loadFresh($load, $control));
                        $ttl = max(1, (int) config('performance.catalog_ttl'));
                        $published = $permit !== null
                            ? $redis->publish($permit, $key, $value, $ttl)
                            : $store->put($key, $value, $ttl);
                        if (! $published) {
                            throw new RuntimeException('Catalog fill was invalidated or expired.');
                        }
                    } finally {
                        if ($permit !== null) {
                            try {
                                $redis->abandon($permit);
                            } catch (Throwable) {
                                // Unused permits expire automatically and cannot publish themselves.
                            }
                        }
                    }
                    $this->status = 'miss';

                    return $value;
                });
                if ($result !== null) {
                    return $result;
                }
                if (! $this->drain()) {
                    break;
                }
            }
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Cache/coordination is optional. A primary read remains authoritative.
        }
        $this->status = 'unavailable';

        return $this->reads->onPrimary($load);
    }

    /** Called after application commits and before cache access; safe to retry. */
    public function drain(): bool
    {
        if (! config('performance.catalog_cache')) {
            return true;
        }
        if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() > 0) {
            return false;
        }
        try {
            // Check under a shared lock first: clean readers need not serialize.
            $dirty = $this->coordinated(false, fn (Connection $control): bool => $this->dirty($control));
            if (! $dirty) {
                return true;
            }

            return $this->coordinated(true, function (Connection $control): bool {
                if ($this->dirty($control)) {
                    if (! $this->store()->flush()) {
                        throw new RuntimeException('Catalog cache deletion failed.');
                    }
                    // A crash before this transaction commits leaves the flag dirty.
                    $control->table('catalog_cache_state')->where('id', 1)->update(['needs_invalidation' => false]);
                }

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    private function boundedFill(callable $load): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $load();
        }
        // A DDL/TRUNCATE writer can take a table lock before its trigger waits
        // for our advisory lock. Bound the data connection's lock wait as well:
        // PostgreSQL cannot detect a cycle spanning two PHP-managed connections.
        $connection = DB::connection();
        $pdos = [$connection->getPdo(), $connection->getReadPdo()];
        $previous = [];
        try {
            foreach ($pdos as $pdo) {
                $id = spl_object_id($pdo);
                if (! isset($previous[$id])) {
                    $previous[$id] = [$pdo, $pdo->query('SHOW lock_timeout')->fetchColumn()];
                    // Session configuration, not an application mutation.
                    $pdo->exec("SET lock_timeout = '500ms'");
                }
            }

            return $load();
        } finally {
            foreach ($previous as [$pdo, $timeout]) {
                try {
                    $pdo->exec('SET lock_timeout = '.$pdo->quote($timeout));
                } catch (Throwable) {
                    // A lost PDO has no surviving session settings to restore.
                }
            }
        }
    }

    private function loadFresh(callable $load, ?Connection $control = null): array
    {
        $ready = false;
        if (DB::getDriverName() === 'pgsql' && $this->reads->enabled() && DB::transactionLevel() === 0) {
            try {
                $control ??= DB::connection('primary_control');
                $lsn = $control->selectOne('SELECT pg_current_wal_lsn()::text AS lsn')->lsn;
                $ready = $this->reads->replayed($lsn);
            } catch (Throwable) {
                // Failure to establish freshness requires an authoritative read.
            }
        }

        return $ready ? $load() : $this->reads->onPrimary($load);
    }

    private function dirty(Connection $control): bool
    {
        $dirty = $control->table('catalog_cache_state')->useWritePdo()->where('id', 1)->value('needs_invalidation');
        if ($dirty === null) {
            throw new RuntimeException('Catalog invalidation state is missing.');
        }

        return (bool) $dirty;
    }

    private function coordinated(bool $exclusive, callable $work): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite/array is a functional test adapter, not concurrency evidence.
            return $work(DB::connection());
        }
        $control = DB::connection('primary_control');

        return $control->transaction(function () use ($control, $exclusive, $work): mixed {
            $control->statement("SET LOCAL lock_timeout = '500ms'");
            $function = $exclusive ? 'pg_advisory_xact_lock' : 'pg_advisory_xact_lock_shared';
            $control->selectOne("SELECT {$function}(?)", [self::LOCK_ID]);

            return $work($control);
        });
    }

    private function store(): Repository
    {
        $name = config('performance.cache_store');
        $config = config('cache.stores.'.$name);
        if (($config['driver'] ?? null) === 'array' && app()->environment('testing')) {
            return Cache::store($name);
        }
        // FLUSHDB ignores prefixes: reject a shared store instead of risking data.
        if (($config['driver'] ?? null) !== 'catalog-snapshots' || ($config['connection'] ?? null) !== 'catalog') {
            throw new RuntimeException('Catalog caching requires its own Redis database.');
        }
        // Resolve URLs exactly as Laravel does: REDIS_URL can override the
        // configured database number of a general-purpose connection.
        $parser = new ConfigurationUrlParser;
        $catalog = $parser->parseConfiguration(config('database.redis.catalog'));
        $database = (string) ($catalog['database'] ?? '');
        if (! ctype_digit($database)) {
            throw new RuntimeException('Catalog caching requires a dedicated Redis database number.');
        }
        foreach (config('database.redis') as $connection => $settings) {
            if (in_array($connection, ['client', 'options', 'catalog', 'clusters'], true)) {
                continue;
            }
            $other = $parser->parseConfiguration($settings);
            if (isset($other['database']) && (int) $other['database'] === (int) $database) {
                throw new RuntimeException('Catalog Redis database is shared with another configured connection.');
            }
        }

        return Cache::store($name);
    }
}
