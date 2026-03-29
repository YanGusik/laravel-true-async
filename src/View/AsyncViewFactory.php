<?php

namespace Spawn\Laravel\View;

use Illuminate\View\Factory;

use function Async\current_context;

/**
 * Coroutine-safe View Factory.
 *
 * Before enableAsync(): share() writes to parent::$shared (boot-time data).
 * After enableAsync(): share() writes to coroutine context only.
 * getShared() merges boot-time $shared with per-coroutine overrides.
 */
class AsyncViewFactory extends Factory
{
    private const CTX_KEY = 'view.shared';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function share($key, $value = null)
    {
        if (! $this->async) {
            return parent::share($key, $value);
        }

        $keys = is_array($key) ? $key : [$key => $value];

        $ctx = current_context();
        $shared = $ctx->find(self::CTX_KEY);

        if ($shared === null) {
            $ctx->set(self::CTX_KEY, $keys);
        } else {
            foreach ($keys as $k => $v) {
                $shared[$k] = $v;
            }
            $ctx->set(self::CTX_KEY, $shared, replace: true);
        }

        return $value;
    }

    public function getShared()
    {
        if (! $this->async) {
            return parent::getShared();
        }

        $perRequest = current_context()->find(self::CTX_KEY) ?? [];

        return array_merge($this->shared, $perRequest);
    }
}
