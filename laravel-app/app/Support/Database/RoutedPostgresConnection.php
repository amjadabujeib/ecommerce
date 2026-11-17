<?php

namespace App\Support\Database;

use App\Support\ReadRouter;
use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;

/** Keep Laravel's operation-based PDO selection; add cross-request WAL fencing. */
final class RoutedPostgresConnection extends PostgresConnection
{
    private bool $readingReplica = false;

    private int $mutationDepth = 0;

    public function getPdo()
    {
        $this->readingReplica = false;
        app(ReadRouter::class)->recordRole('primary');

        return parent::getPdo();
    }

    public function getReadPdo()
    {
        if ($this->transactionLevel() > 0 || $this->readOnWriteConnection || $this->hasModifiedRecords()
            || ! app(ReadRouter::class)->allowsReplica()) {
            return $this->getPdo();
        }
        try {
            $pdo = parent::getReadPdo();
        } catch (\Throwable) {
            app(ReadRouter::class)->usePrimary();

            return $this->getPdo();
        }
        $this->readingReplica = true;
        app(ReadRouter::class)->recordRole('replica');

        return $pdo;
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->readingReplica = false;
        try {
            return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        } catch (QueryException $exception) {
            if (! $this->readingReplica || $this->transactionLevel() > 0 || ! $this->causedByLostConnection($exception->getPrevious())) {
                throw $exception;
            }
            app(ReadRouter::class)->usePrimary();

            return parent::select($query, $bindings, false, $fetchUsing);
        }
    }

    public function statement($query, $bindings = [])
    {
        $this->mutationDepth++;
        try {
            $result = parent::statement($query, $bindings);
        } finally {
            $this->mutationDepth--;
        }
        if ($result && ! $this->pretending()) {
            $this->mutationCommitted();
        }

        return $result;
    }

    public function affectingStatement($query, $bindings = [])
    {
        $this->mutationDepth++;
        try {
            $count = parent::affectingStatement($query, $bindings);
        } finally {
            $this->mutationDepth--;
        }
        if ($count > 0 && ! $this->pretending()) {
            $this->mutationCommitted();
        }

        return $count;
    }

    public function unprepared($query)
    {
        $this->mutationDepth++;
        try {
            $result = parent::unprepared($query);
        } finally {
            $this->mutationDepth--;
        }
        if ($result && ! $this->pretending()) {
            $this->mutationCommitted();
        }

        return $result;
    }

    public function recordsHaveBeenModified($value = true)
    {
        parent::recordsHaveBeenModified($value);
        // Returning query-builder operations declare mutation after successful
        // execution. The statement wrappers defer declarations made before SQL.
        if ($value && $this->mutationDepth === 0 && ! $this->pretending()) {
            $this->mutationCommitted();
        }
    }

    protected function getDefaultPostProcessor()
    {
        return new CommittedPostgresProcessor;
    }

    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        // Laravel normally reconnects and replays outside transactions. A lost
        // write response can follow a commit; only select() may retry a read.
        throw $e;
    }

    private function mutationCommitted(): void
    {
        $router = app(ReadRouter::class);
        if ($this->transactionLevel() > 0) {
            $this->afterCommit(fn () => $router->wrote());
        } else {
            $router->wrote();
        }
    }
}
