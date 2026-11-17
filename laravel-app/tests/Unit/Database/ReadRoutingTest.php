<?php

namespace Tests\Unit\Database;

use App\Support\Database\RoutedPostgresConnection;
use App\Support\ReadRouter;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use Tests\TestCase;

final class ReadRoutingTest extends TestCase
{
    private function connection(bool $ready = true): array
    {
        config(['database.default' => 'pgsql', 'performance.replica' => true]);
        $replica = Mockery::mock(Connection::class);
        $replica->shouldReceive('selectOne')->andReturn((object) ['ready' => $ready]);
        DB::shouldReceive('connection')->with('replica')->andReturn($replica);
        $router = app(ReadRouter::class);
        $router->configure(fn () => '0/500');
        $primary = new PDO('sqlite::memory:');
        $read = new PDO('sqlite::memory:');
        $connection = new RoutedPostgresConnection($primary, 'test', '', ['sticky' => true]);
        $connection->setReadPdo($read);

        return [$connection, $primary, $read, $router];
    }

    public function test_read_selection_and_authoritative_scope_are_independent_of_http_method(): void
    {
        [$connection, $primary, $read, $router] = $this->connection();
        foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
            $this->app['request']->setMethod($method);
            $this->assertSame($read, $connection->getReadPdo());
        }
        $this->assertSame('replica', $router->role());
        try {
            $router->onPrimary(function () use ($connection, $primary, $router): void {
                $this->assertSame($primary, $connection->getReadPdo());
                $router->onPrimary(fn () => $this->assertSame($primary, $connection->getReadPdo()));
                throw new \RuntimeException('Leave primary scope');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($read, $connection->getReadPdo());
        $this->assertSame($primary, $connection->getPdo());
    }

    public function test_lagged_replica_and_same_request_mutations_use_primary(): void
    {
        [$connection, $primary] = $this->connection(false);
        $this->assertSame($primary, $connection->getReadPdo());
        [$connection, $primary] = $this->connection();
        $connection->recordsHaveBeenModified();
        $this->assertSame($primary, $connection->getReadPdo());
    }

    public function test_failed_read_connection_falls_back_without_retrying_a_write(): void
    {
        [$connection, $primary, , $router] = $this->connection();
        $connection->setReadPdo(fn () => throw new \PDOException('connection refused'));
        $this->assertSame($primary, $connection->getReadPdo());
        $this->assertSame('primary', $router->role());
    }

    public function test_console_and_finished_request_do_not_use_replica(): void
    {
        [$connection, $primary, , $router] = $this->connection();
        $router->finish();
        $this->assertSame($primary, $connection->getReadPdo());
        $this->assertFalse($router->enabled());
    }

    public function test_lost_replica_query_retries_once_on_primary(): void
    {
        [$connection, , , $router] = $this->connection();
        $broken = Mockery::mock(PDO::class);
        $broken->shouldReceive('prepare')->once()->andThrow(new \PDOException('server has gone away'));
        $connection->setReadPdo($broken);
        $this->assertSame(1, $connection->selectOne('SELECT 1 AS result')->result);
        $this->assertSame('primary', $router->role());
    }

    public function test_lost_write_is_never_automatically_replayed(): void
    {
        [$connection] = $this->connection();
        $broken = Mockery::mock(PDO::class);
        $broken->shouldReceive('prepare')->once()->andThrow(new \PDOException('server has gone away'));
        $connection->setPdo($broken);
        $connection->setReconnector(fn () => $this->fail('Mutation must not reconnect and replay.'));
        $this->expectException(QueryException::class);
        $connection->statement('INSERT INTO products (title) VALUES (?)', ['test']);
    }

    public function test_reading_explicitly_from_primary_does_not_publish_a_write(): void
    {
        [$connection, , , $router] = $this->connection();
        $this->assertSame(1, $connection->selectFromWriteConnection('SELECT 1 AS result')[0]->result);
        $this->assertFalse($router->hasWrites());
    }
}
