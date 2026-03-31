<?php

namespace Spawn\Laravel\Config;

use Illuminate\Config\Repository;
use Illuminate\Support\Arr;

use function Async\current_context;

/**
 * Coroutine-safe Config Repository.
 *
 * Before bootCompleted(): behaves like stock Repository (writes to $items).
 * After bootCompleted(): set() writes to a per-coroutine overlay in current_context().
 * get() checks overlay first, then falls through to base $items.
 *
 * Base $items are immutable after boot — shared read-only across all coroutines.
 */
class AsyncConfig extends Repository
{
    private const CTX_KEY = 'config.overlay';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function set($key, $value = null)
    {
        if (! $this->async) {
            parent::set($key, $value);
            return;
        }

        $ctx = current_context();
        $overlay = $ctx->find(self::CTX_KEY) ?? [];

        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as $k => $v) {
            Arr::set($overlay, $k, $v);
        }

        $ctx->set(self::CTX_KEY, $overlay, replace: true);
    }

    public function get($key, $default = null)
    {
        if (! $this->async) {
            return parent::get($key, $default);
        }

        if (is_array($key)) {
            return $this->getMany($key);
        }

        $overlay = current_context()->find(self::CTX_KEY);

        if ($overlay !== null && Arr::has($overlay, $key)) {
            return Arr::get($overlay, $key);
        }

        return Arr::get($this->items, $key, $default);
    }

    public function getMany($keys)
    {
        $config = [];

        foreach ($keys as $key => $default) {
            if (is_numeric($key)) {
                [$key, $default] = [$default, null];
            }

            $config[$key] = $this->get($key, $default);
        }

        return $config;
    }

    public function has($key)
    {
        if (! $this->async) {
            return parent::has($key);
        }

        $overlay = current_context()->find(self::CTX_KEY);

        if ($overlay !== null && Arr::has($overlay, $key)) {
            return true;
        }

        return Arr::has($this->items, $key);
    }

    public function all()
    {
        if (! $this->async) {
            return parent::all();
        }

        $overlay = current_context()->find(self::CTX_KEY) ?? [];

        return array_replace_recursive($this->items, $overlay);
    }
}
