<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

// Autoload bench app classes
spl_autoload_register(function (string $class) {
    $prefix = 'Bench\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = dirname(__DIR__) . '/app/' . $relative . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

$app = new Spawn\Laravel\Foundation\AsyncApplication(
    dirname(__DIR__)
);

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    Bench\Http\Kernel::class,
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    Illuminate\Foundation\Console\Kernel::class,
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    Illuminate\Foundation\Exceptions\Handler::class,
);

$app->booted(function () use ($app) {
    $router = $app->make('router');
    require __DIR__ . '/../routes/web.php';
});

return $app;
