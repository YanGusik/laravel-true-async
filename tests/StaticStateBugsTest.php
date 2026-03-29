<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;
use Illuminate\Support\Once;
use Illuminate\View\Compilers\BladeCompiler;

use function Async\delay;

/**
 * Prove that mutable static properties in Laravel framework cause
 * real bugs when accessed from concurrent coroutines.
 */
class StaticStateBugsTest extends AsyncTestCase
{
    // ── Relation::$constraints ──

    public function test_relation_constraints_flag_leaks_between_coroutines(): void
    {
        $prop = new \ReflectionProperty(Relation::class, 'constraints');

        // Ensure initial state
        $prop->setValue(null, true);

        $results = $this->runParallel([
            'a' => function () use ($prop) {
                // Simulate noConstraints() — set to false, delay, check
                return Relation::noConstraints(function () use ($prop) {
                    // Inside noConstraints: should be false
                    $before = $prop->getValue(null);
                    delay(200);
                    // After delay, B may have restored to true, or we still see our false
                    $after = $prop->getValue(null);
                    return compact('before', 'after');
                });
            },
            'b' => function () use ($prop) {
                delay(50);
                // B reads the flag while A is inside noConstraints()
                $seenByB = $prop->getValue(null);
                return ['seenByB' => $seenByB];
            },
        ]);

        // The BUG: B should see constraints=true (it's not inside noConstraints),
        // but A has globally set it to false
        $this->assertFalse($results['b']['seenByB'],
            'BUG: coroutine B sees constraints=false because A called noConstraints()');
    }

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

    // ── BladeCompiler::$componentHashStack ──

    public function test_blade_component_hash_stack_corrupted_by_concurrent_compilation(): void
    {
        $prop = new \ReflectionProperty(BladeCompiler::class, 'componentHashStack');

        // Clear the stack
        $prop->setValue(null, []);

        $results = $this->runParallel([
            'a' => function () use ($prop) {
                // Simulate compiling a component: push hash
                BladeCompiler::newComponentHash('App\\View\\Components\\Alert');
                delay(200);
                // Pop — but B may have pushed to the same stack
                $stack = $prop->getValue(null);
                return ['stackSize' => count($stack)];
            },
            'b' => function () use ($prop) {
                delay(50);
                // B pushes to the same static stack while A is still "compiling"
                BladeCompiler::newComponentHash('App\\View\\Components\\Button');
                $stack = $prop->getValue(null);
                return ['stackSize' => count($stack)];
            },
        ]);

        // BUG: A pushed 1, B pushed 1 to the SAME stack.
        // B sees stack size 2 (both A's and B's hashes) instead of 1
        $this->assertEquals(2, $results['b']['stackSize'],
            'BUG: coroutine B sees both its own and A\'s hash in the shared stack');
    }
}
