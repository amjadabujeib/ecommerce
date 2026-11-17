<?php

namespace App\Support;

use App\Support\Database\WriteCommitted;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Request-scoped consistency policy. HTTP paths and verbs never select a database. */
final class ReadRouter
{
    private ?Closure $fence = null;

    private bool $active = false;

    private bool $resolving = false;

    private bool $resolved = false;

    private bool $replica = false;

    private bool $committed = false;

    private int $primaryDepth = 0;

    private string $lastRole = 'primary';

    public function configure(Closure $fence): void
    {
        $this->fence = $fence;
        $this->active = true;
        $this->resolving = $this->resolved = $this->replica = $this->committed = false;
        $this->primaryDepth = 0;
        $this->lastRole = 'primary';
    }

    public function finish(): void
    {
        $this->active = false;
        $this->fence = null;
    }

    public function enabled(): bool
    {
        return $this->active && config('performance.replica') && config('database.default') === 'pgsql';
    }

    public function onPrimary(callable $work): mixed
    {
        $this->primaryDepth++;
        try {
            return $work();
        } finally {
            $this->primaryDepth--;
        }
    }

    public function allowsReplica(): bool
    {
        if (! $this->enabled() || $this->primaryDepth > 0 || $this->resolving || $this->committed) {
            return false;
        }
        if (! $this->resolved) {
            $this->resolving = true;
            try {
                $this->replica = $this->replayed($this->fence ? ($this->fence)() : null);
                $this->resolved = true;
            } finally {
                $this->resolving = false;
            }
        }

        return $this->replica;
    }

    public function replayed(?string $fence): bool
    {
        try {
            return (bool) DB::connection('replica')->selectOne(
                'SELECT pg_is_in_recovery() AND (?::pg_lsn IS NULL OR pg_last_wal_replay_lsn() >= ?::pg_lsn) AS ready', [$fence, $fence],
            )->ready;
        } catch (Throwable) {
            return false;
        }
    }

    public function usePrimary(): void
    {
        $this->resolved = true;
        $this->replica = false;
    }

    public function wrote(): void
    {
        if ($this->active) {
            $this->committed = true;
            event(new WriteCommitted);
        }
    }

    public function hasWrites(): bool
    {
        return $this->committed;
    }

    public function recordRole(string $role): void
    {
        $this->lastRole = $role;
    }

    public function role(): string
    {
        return $this->lastRole;
    }
}
