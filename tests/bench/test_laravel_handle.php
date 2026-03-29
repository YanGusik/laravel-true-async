<?php

/**
 * Test Laravel kernel->handle() inside a coroutine (no socket).
 * Isolates the crash to Laravel request handling.
 */

use Async\Scope;

$app = require __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
echo "Bootstrap OK\n";

$scope = new Scope();

$scope->spawn(function () use ($app, $kernel) {
    echo "Coroutine started\n";

    $requestScope = Scope::inherit();
    $requestScope->spawn(function () use ($app, $kernel) {
        echo "Request scope started\n";

        $request = Illuminate\Http\Request::create('/ping');

        \Async\current_context()->set(
            Spawn\Laravel\Foundation\ScopedService::REQUEST,
            $request
        );

        echo "Calling kernel->handle()...\n";
        $response = $kernel->handle($request);
        echo "Response: {$response->getStatusCode()} {$response->getContent()}\n";
        $kernel->terminate($request, $response);
        echo "Terminated OK\n";
    });

    $requestScope->awaitCompletion(\Async\timeout(10000));
    $requestScope->dispose();
    echo "Request scope disposed\n";
});

$scope->awaitCompletion(\Async\timeout(15000));
echo "All done\n";
