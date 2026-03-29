<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Spawn\Laravel\Database\CoroutineTransactions;
use function Async\delay;

/**
 * Connection subclass that uses the trait.
 * In real usage, this would be applied to MySqlConnection, etc.
 */
class AsyncMySqlConnection extends MySqlConnection
{
    use CoroutineTransactions;
}

class TransactionIsolationTest extends AsyncTestCase
{
    private function makeMockConnection(string $class): Connection
    {
        // Create a mock PDO that tracks BEGIN/SAVEPOINT calls
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

        $connection = new $class($pdo, 'test');

        return $connection;
    }

    // ── Stock Connection: prove the bug ──

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
                // B begins — but counter is already 1 from A
                $levelBefore = $conn->transactionLevel();
                $conn->beginTransaction();
                $levelAfter = $conn->transactionLevel();
                $conn->commit();
                return ['before' => $levelBefore, 'after' => $levelAfter];
            },
        ]);

        // B should see counter=0 before its begin, but sees 1 (leaked from A)
        $this->assertEquals(1, $results['b']['before'],
            'BUG: B sees A\'s transaction counter — should be 0 but is 1');
    }

    // ── AsyncConnection: prove the fix ──

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
                // Still 1, not affected by B
                $level = $conn->transactionLevel();
                $conn->commit();
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                // B must see 0, not A's 1
                $levelBefore = $conn->transactionLevel();
                $conn->beginTransaction();
                $levelAfter = $conn->transactionLevel();
                $conn->commit();
                return ['before' => $levelBefore, 'after' => $levelAfter];
            },
        ]);

        $this->assertEquals(1, $results['a'], 'A sees its own counter = 1');
        $this->assertEquals(0, $results['b']['before'], 'B sees 0 before begin');
        $this->assertEquals(1, $results['b']['after'], 'B sees 1 after begin');
    }

    public function test_async_connection_nested_transactions_isolated(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();       // level 1 — BEGIN
                $conn->beginTransaction();       // level 2 — SAVEPOINT
                delay(200);
                $level = $conn->transactionLevel();
                $conn->rollBack();               // level 1
                $conn->rollBack();               // level 0
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                $conn->beginTransaction();       // should be level 1 — BEGIN, not SAVEPOINT
                $level = $conn->transactionLevel();
                $conn->commit();
                return $level;
            },
        ]);

        $this->assertEquals(2, $results['a'], 'A has nested transaction level 2');
        $this->assertEquals(1, $results['b'], 'B has own transaction level 1');
    }
}
