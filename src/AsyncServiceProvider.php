<?php

namespace Spawn\Laravel;

use Illuminate\Support\ServiceProvider;
use Spawn\Laravel\Console\FrankenServeCommand;
use Spawn\Laravel\Console\ServeCommand;

class AsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/async.php', 'async');

        $this->commands([
            ServeCommand::class,
            FrankenServeCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/async.php' => config_path('async.php'),
        ], 'async-config');

        $this->registerPermissionAdapter();
    }

    private function registerPermissionAdapter(): void
    {
        if (! class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            return;
        }

        // Override spatie's singleton with our coroutine-safe version.
        // Must run after spatie's PermissionServiceProvider::packageBooted().
        $this->app->singleton(
            \Spatie\Permission\PermissionRegistrar::class,
            \Spawn\Laravel\Permission\AsyncPermissionRegistrar::class,
        );
    }
}
