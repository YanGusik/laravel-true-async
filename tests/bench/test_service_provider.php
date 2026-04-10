<?php

/**
 * Smoke test: AsyncServiceProvider boots correctly and AsyncRouter isolates
 * route state between concurrent requests.
 *
 * Run: php tests/bench/test_service_provider.php
 */

use Async\Scope;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Spawn\Laravel\Foundation\ScopedService;
use Spawn\Laravel\Routing\AsyncRouter;

use function Async\current_context;
use function Async\delay;

$app = require __DIR__ . '/bootstrap/app.php';

// Bootstrap the full stack — ServiceProviders register + boot here
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// ── 1. AsyncRouter is registered ────────────────────────────────────────────
$router = $app->make('router');

if (!$router instanceof AsyncRouter) {
    echo "[FAIL] router is " . get_class($router) . ", expected AsyncRouter\n";
    exit(1);
}
echo "[OK]   AsyncRouter registered\n";

// ── 2. Key adapters are the correct async-safe classes ───────────────────────
$checks = [
    'events'     => \Spawn\Laravel\Events\AsyncDispatcher::class,
    'config'     => \Spawn\Laravel\Config\AsyncConfig::class,
    'translator' => \Spawn\Laravel\Translation\AsyncTranslator::class,
    'view'       => \Spawn\Laravel\View\AsyncViewFactory::class,
];

foreach ($checks as $abstract => $expected) {
    $instance = $app->make($abstract);
    if (!$instance instanceof $expected) {
        echo "[FAIL] '$abstract' is " . get_class($instance) . ", expected $expected\n";
        exit(1);
    }
    echo "[OK]   $abstract → " . class_basename($expected) . "\n";
}

// ── 3. Database connection resolvers are async-safe ───────────────────────────
$dbDrivers = [
    'mysql'    => \Spawn\Laravel\Database\AsyncMySqlConnection::class,
    'mariadb'  => \Spawn\Laravel\Database\AsyncMariaDbConnection::class,
    'pgsql'    => \Spawn\Laravel\Database\AsyncPgsqlConnection::class,
    'sqlite'   => \Spawn\Laravel\Database\AsyncSqliteConnection::class,
    'sqlsrv'   => \Spawn\Laravel\Database\AsyncSqlServerConnection::class,
];

$mockPdo = new class extends \PDO {
    public function __construct() {}
};

foreach ($dbDrivers as $driver => $expected) {
    $resolver = \Illuminate\Database\Connection::getResolver($driver);
    if ($resolver === null) {
        echo "[FAIL] Connection::resolverFor('$driver') is not set\n";
        exit(1);
    }
    $conn = $resolver($mockPdo, 'test', '', []);
    if (!$conn instanceof $expected) {
        echo "[FAIL] '$driver' resolver returned " . get_class($conn) . ", expected $expected\n";
        exit(1);
    }
    echo "[OK]   db.$driver → " . class_basename($expected) . "\n";
}

// ── 4. bootCompleted() — simulate server prepareApp() ────────────────────────
if ($app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
    $app->enableAsyncMode();
}

$router->bootCompleted();
$app->make('events')->bootCompleted();
$app->make('config')->bootCompleted();
$app->make('translator')->bootCompleted();
$app->make('view')->bootCompleted();

echo "[OK]   bootCompleted() on all adapters\n";

// ── 5. Route isolation: concurrent dispatches see their own route/request ─────
//
// We test AsyncRouter directly (not through Kernel) to avoid the
// Kernel constructor / bootstrap ordering issue. The Kernel test
// lives in the integration test suite (laravel-true-async-test/).
//
$router->get('/ping',   fn() => 'pong');
$router->get('/orders', fn() => 'orders');
$router->get('/users',  fn() => 'users');

$scope = new Scope();
$results = [];
$errors  = [];

$scope->setExceptionHandler(function (\Async\Scope $s, \Async\Coroutine $c, \Throwable $e) use (&$errors) {
    $errors[] = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
});

$scope->spawn(function () use ($router, &$results) {
    $routes = [
        'ping'   => Request::create('/ping'),
        'orders' => Request::create('/orders'),
        'users'  => Request::create('/users'),
    ];

    $coros = [];

    foreach ($routes as $name => $request) {
        $coros[$name] = \Async\spawn(function () use ($router, $request, $name) {
            $requestScope = Scope::inherit();
            $result = null;

            $requestScope->spawn(function () use ($router, $request, $name, &$result) {
                // dispatch sets currentRequest in current_context()
                $router->dispatch($request);

                // yield — let other coroutines overwrite Router::$currentRequest
                delay(50 + random_int(0, 30));

                // read after yield — must still be OUR request
                $got = $router->getCurrentRequest()?->getPathInfo();

                $result = [
                    'name'     => $name,
                    'expected' => $request->getPathInfo(),
                    'got'      => $got,
                    'ok'       => $got === $request->getPathInfo(),
                ];
            });

            $requestScope->awaitCompletion(\Async\timeout(5000));
            $requestScope->dispose();

            return $result;
        });
    }

    foreach ($coros as $name => $coro) {
        $results[$name] = \Async\await($coro);
    }
});

$scope->awaitCompletion(\Async\timeout(15000));

// ── Report ───────────────────────────────────────────────────────────────────
echo "\n--- Route isolation (AsyncRouter) ---\n";
$allOk = true;

foreach ($results as $name => $r) {
    if ($r === null) {
        echo "[FAIL] $name — no result\n";
        $allOk = false;
        continue;
    }
    $tag = $r['ok'] ? '[OK]  ' : '[FAIL]';
    echo "$tag $name: got={$r['got']} expected={$r['expected']}\n";
    if (!$r['ok']) $allOk = false;
}

if (!empty($errors)) {
    echo "\n--- Errors ---\n";
    foreach ($errors as $e) {
        echo "[ERR] $e\n";
    }
    $allOk = false;
}

echo "\n" . ($allOk ? "ALL OK\n" : "FAILED\n");
exit($allOk ? 0 : 1);
