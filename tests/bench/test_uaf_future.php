<?php
/**
 * Minimal reproducer for heap-use-after-free in coroutine cancellation.
 * Uses Future::await() to suspend coroutine.
 */

use Async\Scope;
use Async\Future;
use Async\FutureState;

$scope = new Scope();

$state = new FutureState();
$future = new Future($state);

// Coroutine that suspends on Future — will be cancelled during shutdown
$scope->spawn(function () use ($future) {
    echo "Coroutine 1: awaiting future...\n";
    $future->await();
    echo "Coroutine 1: should not reach here\n";
});

// Coroutine that throws to trigger graceful shutdown
$scope->spawn(function () {
    echo "Coroutine 2: throwing\n";
    throw new \RuntimeException("boom");
});

try {
    $scope->awaitCompletion(\Async\timeout(5000));
} catch (\Throwable $e) {
    echo "Caught: " . $e::class . ": " . $e->getMessage() . "\n";
}

echo "Done\n";
