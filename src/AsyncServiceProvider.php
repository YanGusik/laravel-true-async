<?php

namespace TrueAsync\Laravel;

use Illuminate\Support\ServiceProvider;
use TrueAsync\Laravel\Console\ServeCommand;

class AsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([
            ServeCommand::class,
        ]);
    }
}
