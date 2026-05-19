<?php

namespace Spawn\Laravel\Console;

use Illuminate\Console\Command;
use Spawn\Laravel\Server\TrueAsyncServer;
use function Async\await;
use function Async\await_all;
use function Async\spawn;
use function Async\spawn_thread;

class TrueAsyncServeCommand extends Command
{
    protected $signature = 'async:serve
        {--host=    : Override the listener host}
        {--port=    : Override the listener port}
        {--workers= : Number of worker threads (0=auto)}';

    protected $description = 'Start the TrueAsync HTTP server (multi-threaded)';

    public function handle(): int
    {
        if (! class_exists(\TrueAsync\HttpServer::class)) {
            $this->error('The TrueAsync extension is not installed or not loaded.');
            return self::FAILURE;
        }

        $cfg     = config('async.server', []);
        $host    = $this->option('host') ?? $cfg['listeners'][0]['host'] ?? '0.0.0.0';
        $port    = (int) ($this->option('port') ?? $cfg['listeners'][0]['port'] ?? 8080);
        $workers = (int) ($this->option('workers') ?? $cfg['workers'] ?? 0);
        if ($workers <= 0) {
            $workers = $this->detectCoreCount();
        }

        // Merge first listener with CLI overrides, preserve tls/protocol
        $listener = array_merge($cfg['listeners'][0] ?? [], compact('host', 'port'));

        $options = array_merge($cfg, [
            'listeners' => [$listener],
        ]);

        $envVars = $this->captureEnvVars();

        $this->info("Starting TrueAsync server with {$workers} workers on {$host}:{$port}");

        $autoloadPath = base_path('vendor/autoload.php');
        $bootstrapPath = base_path('bootstrap/app.php');

        $main = spawn(function () use ($workers, $options, $envVars, $autoloadPath, $bootstrapPath): void {
            $threads = [];

            for ($i = 0; $i < $workers; $i++) {
                $threads[] = spawn_thread(
                    task: function () use ($options, $envVars, $bootstrapPath): void {
                        // Restore environment inside worker thread
                        foreach ($envVars as $k => $v) {
                            if ($v !== null) {
                                $_SERVER[$k] = $v;
                                $_ENV[$k]    = $v;
                                putenv("{$k}={$v}");
                            }
                        }

                        $app = require $bootstrapPath;
                        $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

                        $server = new TrueAsyncServer($app, $options);
                        $server->start();
                    },
                    bootloader: function () use ($autoloadPath): void {
                        require_once $autoloadPath;
                    }
                );
            }

            await_all($threads);
            echo "threads fine\n";
        });

        await($main);
        echo "main fine\n";

        return self::SUCCESS;
    }

    private function detectCoreCount(): int
    {
        if (is_file('/proc/cpuinfo')) {
            preg_match_all('/^processor/m', file_get_contents('/proc/cpuinfo'), $m);
            return count($m[0]) ?: 1;
        }
        return 1;
    }

    private function captureEnvVars(): array
    {
        $prefixes = ['APP_', 'DATABASE_', 'DEFAULT_URI', 'SYMFONY_', 'TRUASYNC_', 'KERNEL', 'PHP_'];
        $envVars  = [];

        foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
            if (! is_scalar($v) && $v !== null) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $k, $prefix)) {
                    $envVars[$k] = $v;
                    break;
                }
            }
        }

        return $envVars;
    }
}