<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Support\Number;
use Illuminate\Support\Once;

use function Async\delay;

/**
 * Prove that mutable static properties in Laravel framework cause
 * real bugs when accessed from concurrent coroutines.
 *
 * Each test demonstrates a race condition that happens in production
 * when multiple HTTP requests are handled concurrently in one worker.
 */
class StaticStateBugsTest extends AsyncTestCase
{
    // ── Number::$locale ──

    public function test_number_locale_leaks_between_coroutines(): void
    {
        $prop = new \ReflectionProperty(Number::class, 'locale');

        // Reset to default
        Number::useLocale('en');

        $results = $this->runParallel([
            'a' => function () use ($prop) {
                Number::useLocale('de');
                delay(200);
                // After delay, B has changed locale to 'fr'
                return $prop->getValue(null);
            },
            'b' => function () use ($prop) {
                delay(50);
                Number::useLocale('fr');
                return $prop->getValue(null);
            },
        ]);

        // BUG: A set locale to 'de', but after delay sees 'fr' (B's locale)
        $this->assertEquals('fr', $results['a'],
            'BUG: coroutine A sees coroutine B\'s locale instead of its own "de"');
    }

    // ── Once (WeakMap cache) ──

    public function test_once_cache_leaks_between_coroutines(): void
    {
        // Flush any previous once cache
        Once::flush();

        // Shared singleton object — simulates a singleton service
        $service = new class {
            public function getRequestId(): string
            {
                return once(fn () => uniqid('req_', true));
            }
        };

        $results = $this->runParallel([
            'a' => function () use ($service) {
                $id = $service->getRequestId();
                delay(200);
                return $id;
            },
            'b' => function () use ($service) {
                delay(50);
                $id = $service->getRequestId();
                return $id;
            },
        ]);

        // BUG: once() caches by object+method. Since $service is a shared singleton,
        // B gets A's cached value instead of generating its own
        $this->assertEquals($results['a'], $results['b'],
            'BUG: once() on a shared singleton returns cached value from coroutine A to B');
    }
}
