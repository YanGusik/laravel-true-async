<?php

namespace Spawn\Laravel\Telescope;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;
use ArrayIterator;

use function Async\current_context;

/**
 * Array-like object that stores data per-coroutine via current_context().
 *
 * Drop-in replacement for a static array property on a singleton/static class.
 * Supports: $arr[] = $val, count($arr), empty($arr), foreach($arr), collect($arr).
 */
class ContextArray implements ArrayAccess, Countable, IteratorAggregate
{
    public function __construct(
        private readonly string $contextKey,
    ) {}

    private function &items(): array
    {
        $ctx = current_context();
        $items = $ctx->find($this->contextKey);

        if ($items === null) {
            $items = [];
            $ctx->set($this->contextKey, $items);
        }

        return $items;
    }

    private function setItems(array $items): void
    {
        current_context()->set($this->contextKey, $items, replace: true);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $items = $this->items();

        if ($offset === null) {
            $items[] = $value;
        } else {
            $items[$offset] = $value;
        }

        $this->setItems($items);
    }

    public function offsetUnset(mixed $offset): void
    {
        $items = $this->items();
        unset($items[$offset]);
        $this->setItems($items);
    }

    public function count(): int
    {
        return count($this->items());
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items());
    }

    /**
     * Allow `empty($contextArray)` to work correctly.
     * PHP calls count() for objects implementing Countable in empty() checks
     * starting from PHP 8.4, but for older versions we need __serialize.
     * However, Telescope uses `empty(static::$entriesQueue)` which checks
     * if the variable is "empty". For objects, non-null objects are never empty().
     * We need to handle this differently — see note below.
     */

    /**
     * Reset the array for the current coroutine.
     */
    public function clear(): void
    {
        $this->setItems([]);
    }

    /**
     * Get all items as a plain array.
     */
    public function toArray(): array
    {
        return $this->items();
    }
}
