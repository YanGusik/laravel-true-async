<?php
/**
 * Minimal reproducer for heap-use-after-free in coroutine cancellation.
 *
 * ASAN trace shows:
 * - String allocated in cancel_queued_coroutines -> async_new_exception ("Graceful shutdown")
 * - String freed during stream_socket_accept cleanup
 * - Use-after-free in coroutine_object_destroy -> OBJ_RELEASE(exception)
 *
 * The key: a coroutine blocked in stream_socket_accept gets cancelled during
 * graceful shutdown, and the exception's refcount is mismanaged.
 */

use Async\Scope;

// Create a TCP server socket
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) {
    die("Failed to create server: $errstr ($errno)\n");
}

$address = stream_socket_get_name($server, false);
echo "Server listening on $address\n";

stream_set_blocking($server, false);

$scope = new Scope();

// Spawn a coroutine that blocks on stream_socket_accept — this is the one
// that will be cancelled during graceful shutdown
$scope->spawn(function () use ($server) {
    echo "Coroutine: waiting for connection...\n";
    // This will suspend the coroutine waiting for I/O.
    // During graceful shutdown, cancel_queued_coroutines() will create
    // a CancellationException and inject it into this coroutine.
    $client = @stream_socket_accept($server, 30);
    echo "Coroutine: accepted (should not reach here)\n";
});

// Spawn a second coroutine that throws an exception to trigger graceful shutdown
$scope->spawn(function () {
    echo "Coroutine 2: throwing exception to trigger shutdown\n";
    throw new \RuntimeException("Trigger graceful shutdown");
});

try {
    $scope->awaitCompletion(\Async\timeout(5000));
} catch (\Throwable $e) {
    echo "Caught: " . $e::class . ": " . $e->getMessage() . "\n";
}

echo "Cleaning up...\n";
fclose($server);
echo "Done\n";
