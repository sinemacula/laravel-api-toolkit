<?php

namespace Tests\Unit\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\TestCase;

/**
 * Tests for the WritePoolFlushSubscriber.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushSubscriber::class)]
class WritePoolFlushSubscriberTest extends TestCase
{
    /**
     * Test that subscribe registers a listener for RequestHandled.
     *
     * @return void
     */
    public function testSubscribeRegistersListenerForRequestHandled(): void
    {
        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($this->app);

        $subscriber->subscribe($events);

        static::assertTrue($events->hasListeners(RequestHandled::class));
    }

    /**
     * Test that subscribe registers a listener for CommandFinished.
     *
     * @return void
     */
    public function testSubscribeRegistersListenerForCommandFinished(): void
    {
        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($this->app);

        $subscriber->subscribe($events);

        static::assertTrue($events->hasListeners(CommandFinished::class));
    }

    /**
     * Test that subscribe registers a listener for JobProcessed.
     *
     * @return void
     */
    public function testSubscribeRegistersListenerForJobProcessed(): void
    {
        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($this->app);

        $subscriber->subscribe($events);

        static::assertTrue($events->hasListeners(JobProcessed::class));
    }

    /**
     * Test that subscribe registers a listener for JobFailed.
     *
     * @return void
     */
    public function testSubscribeRegistersListenerForJobFailed(): void
    {
        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($this->app);

        $subscriber->subscribe($events);

        static::assertTrue($events->hasListeners(JobFailed::class));
    }

    /**
     * Test that handleFlush resolves the WritePool from the container
     * and calls flush.
     *
     * @return void
     */
    public function testHandleFlushResolvesWritePoolFromContainerAndCallsFlush(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->once()
            ->andReturn($pool);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        static::assertTrue($pool->isEmpty());
    }

    /**
     * Test that handleFlush logs a warning when flush has failures.
     *
     * @return void
     */
    public function testHandleFlushLogsWarningWhenFlushHasFailures(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('nonexistent_table', ['col' => 'val']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')
            ->once()
            ->with(
                \Mockery::on(fn (string $message): bool => str_contains($message, '1 chunk(s) failed out of 1 total.')),
                \Mockery::on(fn (array $context): bool => $context['failure_count'] === 1
                    && $context['total_count']                                      === 1
                    && $context['tables']                                           === ['nonexistent_table']),
            );

        Event::fake([WritePoolFlushFailed::class]);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();
    }

    /**
     * Test that handleFlush dispatches WritePoolFlushFailed event on
     * failure.
     *
     * @return void
     */
    public function testHandleFlushDispatchesWritePoolFlushFailedEventOnFailure(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('nonexistent_table', ['col' => 'val']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->once();

        Event::fake([WritePoolFlushFailed::class]);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 1);
    }

    /**
     * Test that handleFlush does not log a warning on success.
     *
     * @return void
     */
    public function testHandleFlushDoesNotLogWarningOnSuccess(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        Log::shouldReceive('warning')->never();

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();
    }

    /**
     * Test that handleFlush does not dispatch event on success.
     *
     * @return void
     */
    public function testHandleFlushDoesNotDispatchEventOnSuccess(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'secret']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        Event::fake([WritePoolFlushFailed::class]);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        Event::assertNotDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that handleFlush does not throw when event dispatch fails.
     *
     * @return void
     */
    public function testHandleFlushDoesNotThrowWhenEventDispatchFails(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('nonexistent_table', ['col' => 'val']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        Log::shouldReceive('error')->twice();
        Log::shouldReceive('warning')->once();

        Event::shouldReceive('dispatch')
            ->andThrow(new \RuntimeException('Event dispatch failed'));

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();
    }

    /**
     * Test that handleFlush does not throw when flush throws.
     *
     * @return void
     */
    public function testHandleFlushDoesNotThrowWhenFlushThrows(): void
    {
        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andThrow(new \RuntimeException('Database connection lost'));

        Log::shouldReceive('error')
            ->once()
            ->with('WritePool flush subscriber failed', ['error' => 'Database connection lost']);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();
    }
}
