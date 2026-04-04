<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Events\Dispatcher;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

use function Async\delay;

class TelescopeIsolationTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The override Telescope class lives in overrides/ and is normally loaded
        // by a prepending autoloader registered in AsyncServiceProvider::register().
        // In unit tests we load it directly so the class is available.
        require_once __DIR__ . '/../overrides/Telescope.php';

        // Reset Telescope state
        Telescope::$entriesQueue = [];
        Telescope::$updatesQueue = [];
        Telescope::$shouldRecord = false;
        Telescope::$filterUsing = [];
        Telescope::$tagUsing = [];

        // isRecording() needs 'events' service
        $app = $this->createApp();
        $app->singleton('events', fn ($app) => new Dispatcher($app));
    }

    // ── Stock behavior (asyncMode off): prove the bug ──

    public function test_stock_entries_queue_leaks_between_coroutines(): void
    {
        Telescope::$shouldRecord = true;

        $results = $this->runParallel([
            'a' => function () {
                Telescope::$entriesQueue[] = 'entry-a';
                delay(200);
                return Telescope::$entriesQueue;
            },
            'b' => function () {
                delay(50);
                Telescope::$entriesQueue[] = 'entry-b';
                return Telescope::$entriesQueue;
            },
        ]);

        // BUG: B sees A's entries in shared static array
        $this->assertContains('entry-a', $results['b'],
            'BUG: coroutine B sees coroutine A\'s entries in shared queue');
    }

    public function test_stock_should_record_leaks_between_coroutines(): void
    {
        $results = $this->runParallel([
            'a' => function () {
                Telescope::$shouldRecord = true;
                delay(200);
                return Telescope::$shouldRecord;
            },
            'b' => function () {
                delay(50);
                Telescope::$shouldRecord = false;
                return Telescope::$shouldRecord;
            },
        ]);

        // BUG: A set true, B set false — A sees false
        $this->assertFalse($results['a'],
            'BUG: coroutine A sees coroutine B\'s shouldRecord=false');
    }

    // ── Async mode: prove the fix ──

    public function test_async_should_record_isolated(): void
    {
        Telescope::enableAsyncRecording();

        $results = $this->runParallel([
            'a' => function () {
                Telescope::startRecording(false);
                delay(200);
                return Telescope::isRecording();
            },
            'b' => function () {
                delay(50);
                Telescope::stopRecording();
                return Telescope::isRecording();
            },
        ]);

        $this->assertTrue($results['a'], 'A must still be recording');
        $this->assertFalse($results['b'], 'B must be stopped');
    }

    public function test_async_entries_queue_isolated(): void
    {
        Telescope::enableAsyncRecording();

        $results = $this->runParallel([
            'a' => function () {
                Telescope::startRecording(false);
                Telescope::recordLog(IncomingEntry::make(['message' => 'log-a']));
                delay(200);
                return \Async\current_context()->find('telescope.entries') ?? [];
            },
            'b' => function () {
                delay(50);
                Telescope::startRecording(false);
                Telescope::recordLog(IncomingEntry::make(['message' => 'log-b']));
                return \Async\current_context()->find('telescope.entries') ?? [];
            },
        ]);

        $this->assertCount(1, $results['a'], 'A must have exactly 1 entry');
        $this->assertCount(1, $results['b'], 'B must have exactly 1 entry');
        $this->assertEquals('log-a', $results['a'][0]->content['message']);
        $this->assertEquals('log-b', $results['b'][0]->content['message']);
    }

    public function test_async_without_recording_isolated(): void
    {
        Telescope::enableAsyncRecording();

        $results = $this->runParallel([
            'a' => function () {
                Telescope::startRecording(false);
                return Telescope::withoutRecording(function () {
                    delay(200);
                    return Telescope::isRecording();
                });
            },
            'b' => function () {
                delay(50);
                Telescope::startRecording(false);
                return Telescope::isRecording();
            },
        ]);

        $this->assertFalse($results['a'], 'A inside withoutRecording must not record');
        $this->assertTrue($results['b'], 'B must still be recording');
    }
}
