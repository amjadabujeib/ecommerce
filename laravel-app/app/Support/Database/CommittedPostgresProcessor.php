<?php

namespace App\Support\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\PostgresProcessor;

final class CommittedPostgresProcessor extends PostgresProcessor
{
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $connection = $query->getConnection();
        $result = $connection->selectFromWriteConnection($sql, $values)[0];
        // The native processor marks this before executing SQL. Publish the
        // mutation only after INSERT RETURNING actually succeeded instead.
        $connection->recordsHaveBeenModified();
        $sequence ??= 'id';
        $id = is_object($result) ? $result->{$sequence} : $result[$sequence];

        return is_numeric($id) ? (int) $id : $id;
    }
}
