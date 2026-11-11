<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Eligible reads use standby only after the caller's committed WAL is visible. */
final class ReadRouter
{
    private ?Closure $fence = null;

    private bool $resolved = false;

    private ?string $selected = null;

    public function __construct(private readonly Request $request) {}

    public function configure(Closure $fence): void
    {
        $this->fence = $fence;
        $this->resolved = false;
        $this->selected = null;
    }

    public function connection(): ?string
    {
        if (app()->runningInConsole() || ! config('performance.replica') || config('database.default') !== 'pgsql' || ! $this->request->isMethod('GET')) {
            return null;
        }
        if ($this->resolved) {
            return $this->selected;
        }
        $this->resolved = true;
        try {
            $fence = $this->fence ? ($this->fence)() : null;
            $ready = DB::connection('replica')->selectOne(
                'SELECT pg_is_in_recovery() AND (?::pg_lsn IS NULL OR pg_last_wal_replay_lsn() >= ?::pg_lsn) AS ready', [$fence, $fence],
            );
            if ($ready->ready) {
                $this->selected = 'replica';
            }
        } catch (Throwable) {
            // A failed/lagging standby is not permission to return stale data.
            $this->selected = null;
        }

        return $this->selected;
    }

    public function role(): string
    {
        return $this->connection() === 'replica' ? 'replica' : 'primary';
    }

    public function usePrimary(): void
    {
        $this->resolved = true;
        $this->selected = null;
    }
}
