<?php

/**
 * Minimal reproducer: deep recursive exception handling inside a coroutine
 * triggers zend_mm_heap corrupted.
 */

$scope = new Async\Scope();

$scope->spawn(function () {
    function recursive_exception_handler(int $depth = 0): never {
        try {
            throw new \RuntimeException("Error at depth $depth");
        } catch (\Throwable $e) {
            // Simulate what Laravel does: handle exception by calling code
            // that throws another exception
            if ($depth < 50) {
                recursive_exception_handler($depth + 1);
            }
            throw $e;
        }
    }

    recursive_exception_handler();
});

$scope->setExceptionHandler(function (\Throwable $e) {
    echo "Caught: " . $e->getMessage() . "\n";
});

$scope->awaitCompletion(Async\timeout(5000));
echo "Done\n";
