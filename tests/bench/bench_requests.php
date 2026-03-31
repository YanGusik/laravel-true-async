<?php
/**
 * Benchmark: Laravel kernel->handle() in coroutines.
 * Measures throughput for N sequential and N concurrent requests.
 */

use Async\Scope;
use Spawn\Laravel\Foundation\ScopedService;

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$N = (int)($argv[1] ?? 1000);
$route = $argv[2] ?? '/ping';

echo "Benchmark: $N requests to $route\n";
echo str_repeat('-', 50) . "\n";

// --- Sequential (single coroutine, loop) ---
$scope = new Scope();
$scope->spawn(function () use ($kernel, $N, $route) {
    // Warmup
    for ($i = 0; $i < 10; $i++) {
        $req = Illuminate\Http\Request::create($route);
        $resp = $kernel->handle($req);
        $kernel->terminate($req, $resp);
    }

    $start = hrtime(true);
    for ($i = 0; $i < $N; $i++) {
        $req = Illuminate\Http\Request::create($route);
        $resp = $kernel->handle($req);
        $kernel->terminate($req, $resp);
    }
    $elapsed = (hrtime(true) - $start) / 1e6;

    $rps = $N / ($elapsed / 1000);
    printf("Sequential:  %d req in %.1f ms  =>  %.0f req/s  (%.3f ms/req)\n",
        $N, $elapsed, $rps, $elapsed / $N);
});
$scope->awaitCompletion(\Async\timeout(60000));

// --- Concurrent (each request in own scope+coroutine) ---
$scope = new Scope();
$scope->spawn(function () use ($kernel, $N, $route) {
    // Warmup
    for ($i = 0; $i < 10; $i++) {
        $reqScope = Scope::inherit();
        $reqScope->spawn(function () use ($kernel, $route) {
            $req = Illuminate\Http\Request::create($route);
            \Async\current_context()->set(ScopedService::REQUEST, $req);
            $resp = $kernel->handle($req);
            $kernel->terminate($req, $resp);
        });
        $reqScope->awaitCompletion(\Async\timeout(5000));
        $reqScope->dispose();
    }

    $start = hrtime(true);
    $parentScope = new Scope();

    for ($i = 0; $i < $N; $i++) {
        $parentScope->spawn(function () use ($kernel, $route) {
            $reqScope = Scope::inherit();
            $reqScope->spawn(function () use ($kernel, $route) {
                $req = Illuminate\Http\Request::create($route);
                \Async\current_context()->set(ScopedService::REQUEST, $req);
                $resp = $kernel->handle($req);
                $kernel->terminate($req, $resp);
            });
            $reqScope->awaitCompletion(\Async\timeout(5000));
            $reqScope->dispose();
        });
    }

    $parentScope->awaitCompletion(\Async\timeout(60000));
    $elapsed = (hrtime(true) - $start) / 1e6;

    $rps = $N / ($elapsed / 1000);
    printf("Concurrent:  %d req in %.1f ms  =>  %.0f req/s  (%.3f ms/req)\n",
        $N, $elapsed, $rps, $elapsed / $N);
});
$scope->awaitCompletion(\Async\timeout(120000));

echo str_repeat('-', 50) . "\n";
printf("Memory peak: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
echo "Done\n";
