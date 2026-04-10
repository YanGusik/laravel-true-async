<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Spawn\Laravel\Database\AsyncMySqlConnection;
use function Async\delay;

class TransactionIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        // Reset Connection::resolverFor() entries set by registerDatabaseAdapter()
        // so they don't bleed into other test classes.
        (function () {
            static::$resolvers = [];
        })->bindTo(null, Connection::class)();

        parent::tearDown();
    }

    private function makeMockConnection(string $class): Connection
    {
        $pdo = new class extends \PDO {
            public array $log = [];

            public function __construct()
            {
                // no-op, don't connect
            }

            public function beginTransaction(): bool
            {
                $this->log[] = 'BEGIN';
                return true;
            }

            public function commit(): bool
            {
                $this->log[] = 'COMMIT';
                return true;
            }

            public function exec(string $statement): int|false
            {
                $this->log[] = $statement;
                return 0;
            }

            public function inTransaction(): bool
            {
                return false;
            }

            public function rollBack(): bool
            {
                $this->log[] = 'ROLLBACK';
                return true;
            }
        };

        return new $class($pdo, 'test');
    }

    // ── Stock Connection: prove the bug ──────────────────────────────────────

    public function test_stock_connection_transaction_counter_leaks(): void
    {
        $conn = $this->makeMockConnection(MySqlConnection::class);

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();
                delay(200);
                $level = $conn->transactionLevel();
                $conn->commit();
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                $levelBefore = $conn->transactionLevel();
                $conn->beginTransaction();
                $levelAfter  = $conn->transactionLevel();
                $conn->commit();
                return ['before' => $levelBefore, 'after' => $levelAfter];
            },
        ]);

        $this->assertEquals(1, $results['b']['before'],
            'BUG confirmed: B sees A\'s transaction counter — should be 0 but is 1');
    }

    // ── AsyncMySqlConnection: counter isolated per coroutine ─────────────────

    public function test_async_connection_transaction_counter_isolated(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $this->assertEquals(0, $conn->transactionLevel());
                $conn->beginTransaction();
                $this->assertEquals(1, $conn->transactionLevel());
                delay(200);
                $level = $conn->transactionLevel(); // still 1, not affected by B
                $conn->commit();
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                $levelBefore = $conn->transactionLevel(); // must be 0, not A's 1
                $conn->beginTransaction();
                $levelAfter = $conn->transactionLevel();
                $conn->commit();
                return ['before' => $levelBefore, 'after' => $levelAfter];
            },
        ]);

        $this->assertEquals(1, $results['a'], 'A sees its own counter = 1');
        $this->assertEquals(0, $results['b']['before'], 'B sees 0 before begin (no leak from A)');
        $this->assertEquals(1, $results['b']['after'], 'B sees 1 after own begin');
    }

    public function test_async_connection_nested_transactions_isolated(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();   // level 1 — BEGIN
                $conn->beginTransaction();   // level 2 — SAVEPOINT
                delay(200);
                $level = $conn->transactionLevel();
                $conn->rollBack();           // level 1
                $conn->rollBack();           // level 0
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                // B must start a real BEGIN, not SAVEPOINT
                $conn->beginTransaction();
                $level = $conn->transactionLevel();
                $conn->commit();
                return $level;
            },
        ]);

        $this->assertEquals(2, $results['a'], 'A has nested transaction level 2');
        $this->assertEquals(1, $results['b'], 'B has own transaction level 1 (not nested into A)');
    }

    // ── AsyncMySqlConnection in async app mode (no bootCompleted) ────────────

    /**
     * In production the connection detects async mode via AsyncApplication::isAsyncModeEnabled()
     * without needing an explicit bootCompleted() call.
     */
    public function test_async_connection_detects_app_async_mode(): void
    {
        $app = new \Spawn\Laravel\Foundation\AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new \Illuminate\Config\Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
        ]));
        $app->enableAsyncMode();

        // bind app into the container so app() helper finds it
        \Illuminate\Container\Container::setInstance($app);

        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        // Note: no bootCompleted() — relies on AsyncApplication::isAsyncModeEnabled()

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();
                delay(200);
                $level = $conn->transactionLevel();
                $conn->commit();
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                $before = $conn->transactionLevel();
                $conn->beginTransaction();
                $after  = $conn->transactionLevel();
                $conn->commit();
                return ['before' => $before, 'after' => $after];
            },
        ]);

        $this->assertEquals(1, $results['a']);
        $this->assertEquals(0, $results['b']['before'], 'App async mode detected — no counter leak');
        $this->assertEquals(1, $results['b']['after']);

        \Illuminate\Container\Container::setInstance(null);
    }
}
