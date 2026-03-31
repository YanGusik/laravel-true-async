<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Events\Dispatcher;
use function Async\delay;

class EventsIsolationTest extends AsyncTestCase
{
    /**
     * Dispatcher as a singleton is safe for normal dispatch() across coroutines —
     * listeners are registered at boot and shared (read-only at runtime).
     */
    public function test_dispatcher_listeners_shared_safely(): void
    {
        $app = $this->createApp();
        $dispatcher = new Dispatcher($app);

        $fired = [];

        $dispatcher->listen('ping', function ($who) use (&$fired) {
            $fired[] = $who;
        });

        $this->runParallel([
            'a' => function () use ($dispatcher) {
                $dispatcher->dispatch('ping', ['A']);
            },
            'b' => function () use ($dispatcher) {
                $dispatcher->dispatch('ping', ['B']);
            },
        ]);

        $this->assertContains('A', $fired);
        $this->assertContains('B', $fired);
        $this->assertCount(2, $fired);
    }

    /**
     * Document known limitation: Event::defer() uses shared instance state.
     * If two coroutines call defer() concurrently, the deferring flag leaks.
     * This is a user-space API, not called by Laravel internals during requests.
     */
    public function test_defer_flag_leaks_between_coroutines_known_limitation(): void
    {
        $app = $this->createApp();
        $dispatcher = new Dispatcher($app);

        $timeline = [];

        $dispatcher->listen('ping', function ($who) use (&$timeline) {
            $timeline[] = "fired:$who";
        });

        $this->runParallel([
            'a' => function () use ($dispatcher, &$timeline) {
                $dispatcher->defer(function () use ($dispatcher, &$timeline) {
                    $timeline[] = 'a:defer-start';
                    delay(300);
                    $timeline[] = 'a:defer-end';
                    return 'a';
                });
            },
            'b' => function () use ($dispatcher, &$timeline) {
                delay(100);
                $timeline[] = 'b:before-dispatch';
                $dispatcher->dispatch('ping', ['B']);
                $timeline[] = 'b:after-dispatch';
            },
        ]);

        $beforeIdx = array_search('b:before-dispatch', $timeline);
        $afterIdx  = array_search('b:after-dispatch', $timeline);
        $firedIdx  = array_search('fired:B', $timeline);

        $this->assertNotFalse($firedIdx, 'B event must have fired');

        // Known limitation: B's event is deferred (fires after b:after-dispatch)
        // because A's $deferringEvents=true leaks to B.
        $this->assertGreaterThanOrEqual($afterIdx, $firedIdx,
            'Known limitation: defer() flag leaks between coroutines');
    }
}
