<?php

namespace Spawn\Laravel\Tests;

use Inertia\ResponseFactory;

use function Async\delay;

class InertiaIsolationTest extends AsyncTestCase
{
    private function makeFactory(string $class = ResponseFactory::class): ResponseFactory
    {
        return new $class();
    }

    // ── Stock ResponseFactory: prove the bugs ──

    public function test_stock_factory_shared_props_leak(): void
    {
        $factory = $this->makeFactory();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->share(['user' => 'Alice']);
                delay(200);
                return $factory->getShared();
            },
            'b' => function () use ($factory) {
                delay(50);
                $factory->share(['user' => 'Bob']);
                return $factory->getShared();
            },
        ]);

        // BUG: A shared user=Alice, but B overwrote it.
        // A sees both users merged, or just Bob's.
        $this->assertEquals('Bob', $results['a']['user'],
            'BUG: coroutine A sees coroutine B\'s shared props');
    }

    public function test_stock_factory_shared_props_accumulate(): void
    {
        $factory = $this->makeFactory();

        // Simulate two sequential "requests" on the same singleton
        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->share(['flash' => 'Success!']);
                delay(200);
                return $factory->getShared();
            },
            'b' => function () use ($factory) {
                delay(250);
                // B runs after A, but the singleton still has A's shared data
                $factory->share(['theme' => 'dark']);
                return $factory->getShared();
            },
        ]);

        // BUG: B sees A's flash message — data accumulates in singleton
        $this->assertArrayHasKey('flash', $results['b'],
            'BUG: coroutine B sees coroutine A\'s leftover shared props');
    }

    public function test_stock_factory_root_view_leaks(): void
    {
        $factory = $this->makeFactory();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->setRootView('app');
                delay(200);
                $ref = new \ReflectionProperty($factory, 'rootView');
                return $ref->getValue($factory);
            },
            'b' => function () use ($factory) {
                delay(50);
                $factory->setRootView('admin');
                $ref = new \ReflectionProperty($factory, 'rootView');
                return $ref->getValue($factory);
            },
        ]);

        // BUG: A set rootView to 'app', but B changed it to 'admin'
        $this->assertEquals('admin', $results['a'],
            'BUG: coroutine A sees coroutine B\'s root view');
    }

    public function test_stock_factory_version_leaks(): void
    {
        $factory = $this->makeFactory();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->version('v1');
                delay(200);
                $ref = new \ReflectionProperty($factory, 'version');
                return $ref->getValue($factory);
            },
            'b' => function () use ($factory) {
                delay(50);
                $factory->version('v2');
                $ref = new \ReflectionProperty($factory, 'version');
                return $ref->getValue($factory);
            },
        ]);

        $this->assertEquals('v2', $results['a'],
            'BUG: coroutine A sees coroutine B\'s version');
    }

    // ── AsyncResponseFactory: prove the fix ──

    public function test_async_factory_shared_props_isolated(): void
    {
        $factory = $this->makeFactory(\Spawn\Laravel\Inertia\AsyncResponseFactory::class);
        $factory->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->share(['user' => 'Alice']);
                delay(200);
                return $factory->getShared();
            },
            'b' => function () use ($factory) {
                delay(50);
                $factory->share(['user' => 'Bob']);
                return $factory->getShared();
            },
        ]);

        $this->assertEquals('Alice', $results['a']['user'], 'A must see its own shared props');
        $this->assertEquals('Bob', $results['b']['user'], 'B must see its own shared props');
    }

    public function test_async_factory_shared_props_dont_accumulate(): void
    {
        $factory = $this->makeFactory(\Spawn\Laravel\Inertia\AsyncResponseFactory::class);
        $factory->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->share(['flash' => 'Success!']);
                delay(200);
                return $factory->getShared();
            },
            'b' => function () use ($factory) {
                delay(250);
                $factory->share(['theme' => 'dark']);
                return $factory->getShared();
            },
        ]);

        $this->assertArrayNotHasKey('flash', $results['b'], 'B must not see A\'s shared props');
        $this->assertArrayHasKey('theme', $results['b'], 'B must see its own shared props');
    }

    public function test_async_factory_root_view_isolated(): void
    {
        $factory = $this->makeFactory(\Spawn\Laravel\Inertia\AsyncResponseFactory::class);
        $factory->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->setRootView('app');
                delay(200);
                return $factory->getRootView();
            },
            'b' => function () use ($factory) {
                delay(50);
                $factory->setRootView('admin');
                return $factory->getRootView();
            },
        ]);

        $this->assertEquals('app', $results['a'], 'A must see its own root view');
        $this->assertEquals('admin', $results['b'], 'B must see its own root view');
    }

    public function test_async_factory_version_isolated(): void
    {
        $factory = $this->makeFactory(\Spawn\Laravel\Inertia\AsyncResponseFactory::class);
        $factory->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->version('v1');
                delay(200);
                return $factory->getVersion();
            },
            'b' => function () use ($factory) {
                delay(50);
                $factory->version('v2');
                return $factory->getVersion();
            },
        ]);

        $this->assertEquals('v1', $results['a'], 'A must see its own version');
        $this->assertEquals('v2', $results['b'], 'B must see its own version');
    }

    public function test_async_factory_before_boot_behaves_as_stock(): void
    {
        $factory = $this->makeFactory(\Spawn\Laravel\Inertia\AsyncResponseFactory::class);
        // bootCompleted() NOT called

        $factory->share(['key' => 'value']);
        $this->assertEquals('value', $factory->getShared('key'));

        $factory->setRootView('custom');
        $ref = new \ReflectionProperty($factory, 'rootView');
        $this->assertEquals('custom', $ref->getValue($factory));
    }
}
