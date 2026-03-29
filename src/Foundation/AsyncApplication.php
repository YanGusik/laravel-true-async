<?php

namespace Spawn\Laravel\Foundation;

use Closure;
use Illuminate\Foundation\Application;

use function Async\current_context;

class AsyncApplication extends Application
{
    /**
     * Scoped services that are safe to proxy via offsetGet (used by Facades).
     * Services that get passed to typed PHP parameters must NOT be here,
     * because ScopedServiceProxy does not extend/implement their types.
     *
     * 'cookie' is excluded: AuthManager passes $app['cookie'] to setCookieJar(QueueingFactory).
     * 'auth.driver' is excluded: guards are passed to typed parameters in some middleware.
     */
    private const FACADE_PROXIED = [
        'auth',
        'session',
    ];

    /**
     * True while the async HTTP server is running.
     */
    private bool $asyncMode = false;

    /**
     * User-registered scoped factories: abstract => Closure.
     */
    private array $scopedBindings = [];

    public function enableAsyncMode(): void
    {
        $this->asyncMode = true;
    }

    public function scopedSingleton(string $abstract, Closure $factory): void
    {
        $this->scopedBindings[$abstract] = $factory;
    }

    public function offsetGet($key): mixed
    {
        if ($this->asyncMode) {
            $alias = $this->getAlias($key);

            if (in_array($alias, self::FACADE_PROXIED, true)) {
                return new ScopedServiceProxy(fn() => $this->resolveScoped($alias));
            }
        }

        return parent::offsetGet($key);
    }

    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        if (!$this->asyncMode) {
            return parent::resolve($abstract, $parameters, $raiseEvents);
        }

        $alias = $this->getAlias($abstract);

        if ($this->isScopedService($alias)) {
            return $this->resolveScoped($alias);
        }

        return parent::resolve($abstract, $parameters, $raiseEvents);
    }

    private function isScopedService(string $alias): bool
    {
        if (ScopedService::tryFrom($alias) !== null) {
            return true;
        }

        if (isset($this->scopedBindings[$alias])) {
            return true;
        }

        if ($this->resolved('config')) {
            $scoped = parent::resolve('config')->get('async.scoped_services', []);
            return in_array($alias, $scoped, true);
        }

        return false;
    }

    private function resolveScoped(string $alias): mixed
    {
        $ctx = current_context();
        $key = ScopedService::tryFrom($alias) ?? $alias;

        $instance = $ctx->find($key);

        if ($instance !== null) {
            return $instance;
        }

        if (isset($this->scopedBindings[$alias])) {
            $instance = ($this->scopedBindings[$alias])($this);
        } else {
            $bindings = $this->getBindings();
            if (isset($bindings[$alias])) {
                $concrete = $bindings[$alias]['concrete'];
                $instance = $concrete instanceof \Closure ? $concrete($this) : $this->build($concrete);
            } else {
                $instance = $this->build($alias);
            }
        }

        $ctx->set($key, $instance);

        return $instance;
    }
}
