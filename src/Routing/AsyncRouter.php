<?php

namespace Spawn\Laravel\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;

use function Async\current_context;

/**
 * Coroutine-safe Router.
 *
 * Stores $current and $currentRequest in per-coroutine context
 * instead of shared instance properties.
 */
class AsyncRouter extends Router
{
    private const CTX_CURRENT_ROUTE   = 'router.current';
    private const CTX_CURRENT_REQUEST = 'router.currentRequest';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function dispatch(Request $request)
    {
        if ($this->async) {
            $ctx = current_context();
            $ctx->set(self::CTX_CURRENT_REQUEST, $request);
        } else {
            $this->currentRequest = $request;
        }

        return $this->dispatchToRoute($request);
    }

    protected function findRoute($request)
    {
        $this->events->dispatch(new \Illuminate\Routing\Events\Routing($request));

        $route = $this->routes->match($request);

        if ($this->async) {
            current_context()->set(self::CTX_CURRENT_ROUTE, $route);
        } else {
            $this->current = $route;
        }

        $route->setContainer($this->container);

        $this->container->instance(\Illuminate\Routing\Route::class, $route);

        return $route;
    }

    public function current()
    {
        if ($this->async) {
            return current_context()->find(self::CTX_CURRENT_ROUTE);
        }

        return $this->current;
    }

    public function getCurrentRequest()
    {
        if ($this->async) {
            return current_context()->find(self::CTX_CURRENT_REQUEST);
        }

        return $this->currentRequest;
    }
}
