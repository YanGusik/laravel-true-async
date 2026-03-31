<?php

/**
 * Minimal async TCP server WITHOUT Laravel.
 * Tests whether the crash is in socket/scope handling or in Laravel.
 */

use Async\Scope;
use Async\FutureState;
use Async\Future;

require_once __DIR__ . '/../../vendor/autoload.php';

$scope = new Scope();

$scope->spawn(function () use ($scope) {
    $socket = stream_socket_server("tcp://0.0.0.0:8892");
    echo "Listening on tcp://0.0.0.0:8892\n";

    while (true) {
        $client = @stream_socket_accept($socket, timeout: -1);

        if ($client === false) {
            continue;
        }

        $requestScope = Scope::inherit($scope);
        $requestScope->spawn(function () use ($client, $requestScope) {
            try {
                $raw = fread($client, 8192);
                $body = "pong";
                $response = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
                fwrite($client, $response);
            } finally {
                $requestScope->dispose();
                fclose($client);
            }
        });
    }
});

$shutdownState = new FutureState();
$shutdownFuture = (new Future($shutdownState))->ignore();

try {
    $scope->awaitCompletion($shutdownFuture);
} catch (\Async\AsyncCancellation) {
    $scope->cancel();
}
