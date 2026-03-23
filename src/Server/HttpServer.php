<?php

namespace TrueAsync\Laravel\Server;

use Async\Future;
use Async\FutureState;
use Async\Scope;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;

class HttpServer
{
    public function __construct(
        private readonly Application $app,
        private readonly string $host,
        private readonly int $port,
    ) {}

    public function start(): void
    {
        $shutdownState = new FutureState();
        $shutdownFuture = new Future($shutdownState);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $shutdownState->complete(null));
        pcntl_signal(SIGINT, fn() => $shutdownState->complete(null));

        $serverScope = new Scope();

        $serverScope->spawn(function () use ($serverScope) {
            $socket = stream_socket_server("tcp://{$this->host}:{$this->port}");

            if ($socket === false) {
                throw new \RuntimeException("Failed to bind tcp://{$this->host}:{$this->port}");
            }

            echo "Listening on tcp://{$this->host}:{$this->port}\n";

            while (true) {
                $client = stream_socket_accept($socket, timeout: -1);

                if ($client === false) {
                    continue;
                }

                $requestScope = Scope::inherit($serverScope);
                $requestScope->spawn($this->handleConnection(...), $client);
            }
        });

        try {
            $serverScope->awaitCompletion($shutdownFuture);
        } catch (\Async\AsyncCancellation) {
            $serverScope->cancel();
        }
    }

    private function handleConnection(mixed $client): void
    {
        try {
            $raw = $this->readRaw($client);

            if ($raw === '') {
                return;
            }

            $request = RequestParser::parse($raw);

            $kernel = $this->app->make(Kernel::class);
            $response = $kernel->handle($request);

            ResponseEmitter::emit($client, $response);

            $kernel->terminate($request, $response);
        } finally {
            fclose($client);
        }
    }

    private function readRaw(mixed $client): string
    {
        $raw = '';

        while ($chunk = fread($client, 8192)) {
            $raw .= $chunk;

            if (str_contains($raw, "\r\n\r\n")) {
                if (preg_match('/Content-Length:\s*(\d+)/i', $raw, $m)) {
                    $headerEnd = strpos($raw, "\r\n\r\n") + 4;
                    $bodyLength = (int) $m[1];
                    $bodyRead = strlen($raw) - $headerEnd;

                    while ($bodyRead < $bodyLength) {
                        $chunk = fread($client, $bodyLength - $bodyRead);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $raw .= $chunk;
                        $bodyRead += strlen($chunk);
                    }
                }

                break;
            }
        }

        return $raw;
    }
}
