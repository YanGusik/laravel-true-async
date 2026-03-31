<?php

namespace Spawn\Laravel\Database;

use function Async\coroutine_context;

/**
 * Stores the transaction counter in coroutine context instead of
 * the shared Connection instance property.
 *
 * PDO Pool gives each coroutine its own physical connection,
 * so the transaction counter must also be per-coroutine.
 *
 * Usage: `use CoroutineTransactions;` in a Connection subclass.
 * This trait overrides ManagesTransactions::transactionLevel()
 * and intercepts all reads/writes to $this->transactions.
 */
trait CoroutineTransactions
{
    private const CTX_TRANSACTIONS = 'db.transactions';

    private bool $asyncTransactions = false;

    public function bootCompleted(): void
    {
        $this->asyncTransactions = true;
    }

    public function transactionLevel()
    {
        if ($this->asyncTransactions) {
            return coroutine_context()->find(self::CTX_TRANSACTIONS) ?? 0;
        }

        return $this->transactions;
    }

    private function setTransactionLevel(int $level): void
    {
        if ($this->asyncTransactions) {
            $ctx = coroutine_context();
            if ($ctx->find(self::CTX_TRANSACTIONS) === null) {
                $ctx->set(self::CTX_TRANSACTIONS, $level);
            } else {
                $ctx->set(self::CTX_TRANSACTIONS, $level, replace: true);
            }
        } else {
            $this->transactions = $level;
        }
    }

    public function beginTransaction()
    {
        foreach ($this->beforeStartingTransaction as $callback) {
            $callback($this);
        }

        $this->createTransaction();

        $this->setTransactionLevel($this->transactionLevel() + 1);

        $this->transactionsManager?->begin(
            $this->getName(), $this->transactionLevel()
        );

        $this->fireConnectionEvent('beganTransaction');
    }

    protected function createTransaction()
    {
        if ($this->transactionLevel() == 0) {
            $this->reconnectIfMissingConnection();

            try {
                $this->executeBeginTransactionStatement();
            } catch (\Throwable $e) {
                $this->handleBeginTransactionException($e);
            }
        } elseif ($this->transactionLevel() >= 1 && $this->queryGrammar->supportsSavepoints()) {
            $this->createSavepoint();
        }
    }

    protected function createSavepoint()
    {
        $this->getPdo()->exec(
            $this->queryGrammar->compileSavepoint('trans'.($this->transactionLevel() + 1))
        );
    }

    public function commit()
    {
        if ($this->transactionLevel() == 1) {
            $this->fireConnectionEvent('committing');
            $this->getPdo()->commit();
        }

        $levelBeingCommitted = $this->transactionLevel();
        $this->setTransactionLevel(max(0, $this->transactionLevel() - 1));

        $this->transactionsManager?->commit(
            $this->getName(), $levelBeingCommitted, $this->transactionLevel()
        );

        $this->fireConnectionEvent('committed');
    }

    public function rollBack($toLevel = null)
    {
        $toLevel = is_null($toLevel)
            ? $this->transactionLevel() - 1
            : $toLevel;

        if ($toLevel < 0 || $toLevel >= $this->transactionLevel()) {
            return;
        }

        try {
            $this->performRollBack($toLevel);
        } catch (\Throwable $e) {
            $this->handleRollBackException($e);
        }

        $this->setTransactionLevel($toLevel);

        $this->transactionsManager?->rollback(
            $this->getName(), $this->transactionLevel()
        );

        $this->fireConnectionEvent('rollingBack');
    }

    protected function handleRollBackException(\Throwable $e)
    {
        if ($this->causedByLostConnection($e)) {
            $this->setTransactionLevel(0);

            $this->transactionsManager?->rollback(
                $this->getName(), $this->transactionLevel()
            );
        }

        throw $e;
    }

    protected function handleCommitTransactionException(\Throwable $e, $currentAttempt, $maxAttempts)
    {
        $this->setTransactionLevel(max(0, $this->transactionLevel() - 1));

        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) {
            $pdo = $this->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return;
        }

        if ($this->causedByLostConnection($e)) {
            $this->setTransactionLevel(0);
        }

        throw $e;
    }

    protected function handleTransactionException(\Throwable $e, $currentAttempt, $maxAttempts)
    {
        if ($this->causedByConcurrencyError($e) &&
            $this->transactionLevel() > 1) {
            $this->setTransactionLevel($this->transactionLevel() - 1);

            $this->transactionsManager?->rollback(
                $this->getName(), $this->transactionLevel()
            );

            throw new \Illuminate\Database\DeadlockException(
                $e->getMessage(), is_int($e->getCode()) ? $e->getCode() : 0, $e
            );
        }

        $this->rollBack();

        if ($this->causedByConcurrencyError($e) &&
            $currentAttempt < $maxAttempts) {
            return;
        }

        throw $e;
    }
}
