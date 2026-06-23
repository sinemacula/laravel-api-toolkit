<?php

declare(strict_types = 1);

namespace Tests\Unit\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteCacheInvalidator;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
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
final class WritePoolFlushSubscriberTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * The per-query cache invalidation path is covered by its own
     * dedicated tests below; disable it here so the flush and escalation
     * assertions observe the subscriber in isolation without the
     * container being asked for the invalidator.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);
    }

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

        self::assertTrue($events->hasListeners(RequestHandled::class));
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

        self::assertTrue($events->hasListeners(CommandFinished::class));
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

        self::assertTrue($events->hasListeners(JobProcessed::class));
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

        self::assertTrue($events->hasListeners(JobFailed::class));
    }

    /**
     * Test that dispatching RequestHandled flushes the pool through the
     * registered listener.
     *
     * @return void
     */
    public function testDispatchingRequestHandledFlushesThePool(): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('request@example.com');

        $events->dispatch(new RequestHandled(Request::create('/test', 'GET'), new Response));

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that dispatching CommandFinished flushes the pool through the
     * registered listener.
     *
     * @return void
     */
    public function testDispatchingCommandFinishedFlushesThePool(): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('command@example.com');

        $events->dispatch(new CommandFinished('test:command', new ArrayInput([]), new NullOutput, 0));

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that dispatching JobProcessed flushes the pool through the
     * registered listener.
     *
     * @return void
     */
    public function testDispatchingJobProcessedFlushesThePool(): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('job@example.com');

        $events->dispatch(new JobProcessed('sync', self::createStub(Job::class)));

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that dispatching JobFailed flushes the pool through the
     * registered listener.
     *
     * @return void
     */
    public function testDispatchingJobFailedFlushesThePool(): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('failed-job@example.com');

        $events->dispatch(new JobFailed('sync', self::createStub(Job::class), new \RuntimeException('Job failure')));

        self::assertTrue($pool->isEmpty());
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

        self::assertTrue($pool->isEmpty());
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

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')
            ->once()
            ->with(
                \Mockery::on(fn (string $message): bool => $message === 'WritePool flush completed with failures: 1 chunk(s) failed out of 1 total.'),
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

        Log::shouldReceive('error')->never();
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

        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->once();

        Event::shouldReceive('dispatch')
            ->andThrow(new \RuntimeException('Event dispatch failed'));

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();
    }

    /**
     * Test that an unexpected throwable during pool resolution is
     * caught and logged at error level without crashing the boundary.
     *
     * @return void
     */
    public function testHandleFlushLogsErrorWhenPoolResolutionThrows(): void
    {
        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andThrow(new \RuntimeException('Database connection lost'));

        Log::shouldReceive('error')
            ->once()
            ->with('WritePool flush subscriber failed', \Mockery::on(
                static fn (array $context): bool => $context['error'] === 'Database connection lost'
                    && $context['exception'] instanceof \RuntimeException
                    && $context['exception']->getMessage() === 'Database connection lost',
            ));

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();
    }

    /**
     * Test that a WritePoolFlushException raised by the throw strategy
     * is escalated loudly with a warning and a dispatched event rather
     * than being swallowed into a generic error log.
     *
     * @return void
     */
    public function testHandleFlushEscalatesThrowStrategyFailureLoudly(): void
    {
        $pool = new WritePool(500, 10000, FlushStrategy::THROW);
        $pool->add('nonexistent_table', ['col' => 'val']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once();

        Event::fake([WritePoolFlushFailed::class]);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 1);
    }

    /**
     * Test that the subscriber does not re-throw a flush exception when
     * the rethrow_at_boundary flag is disabled.
     *
     * @return void
     */
    public function testHandleFlushDoesNotRethrowWhenRethrowDisabled(): void
    {
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', false);

        $pool = new WritePool(500, 10000, FlushStrategy::THROW);
        $pool->add('nonexistent_table', ['col' => 'val']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        Log::shouldReceive('warning')->once();

        Event::fake([WritePoolFlushFailed::class]);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that the subscriber re-throws a flush exception after
     * escalating it when the rethrow_at_boundary flag is enabled.
     *
     * @return void
     */
    public function testHandleFlushRethrowsWhenRethrowEnabled(): void
    {
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', true);

        $pool = new WritePool(500, 10000, FlushStrategy::THROW);
        $pool->add('nonexistent_table', ['col' => 'val']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        Log::shouldReceive('warning')->once();

        Event::fake([WritePoolFlushFailed::class]);

        $subscriber = new WritePoolFlushSubscriber($container);

        $this->expectException(WritePoolFlushException::class);

        $subscriber->handleFlush();
    }

    /**
     * Test that, when invalidation is enabled, the subscriber invalidates
     * the per-query cache for every table the flush persisted.
     *
     * @return void
     */
    public function testHandleFlushInvalidatesQueryCacheForFlushedTables(): void
    {
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);

        $invalidator = new class {
            /** @var list<array<int, string>> The arguments of each invalidate() call. */
            public array $calls = [];

            /**
             * @param  array<int, string>  $tables
             * @return void
             */
            public function invalidate(array $tables): void
            {
                $this->calls[] = $tables;
            }
        };

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')->with(WritePool::class)->andReturn($pool);
        $container->shouldReceive('make')->with(DeferredWriteCacheInvalidator::class)->andReturn($invalidator);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        static::assertTrue($pool->isEmpty());
        static::assertSame([['users']], $invalidator->calls);
    }

    /**
     * Test that, when invalidation is disabled, the subscriber never
     * resolves the invalidator from the container.
     *
     * @return void
     */
    public function testHandleFlushDoesNotInvalidateQueryCacheWhenDisabled(): void
    {
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);

        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')->with(WritePool::class)->andReturn($pool);
        $container->shouldReceive('make')->with(DeferredWriteCacheInvalidator::class)->never();

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        static::assertTrue($pool->isEmpty());
    }

    /**
     * Test that a throw-strategy failure still invalidates the per-query
     * cache for every attempted table before the failure is escalated.
     *
     * @return void
     */
    public function testHandleFlushInvalidatesQueryCacheOnFlushException(): void
    {
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', false);

        $pool = new WritePool(500, 10000, FlushStrategy::THROW);
        $pool->add('users', ['name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'secret']);
        $pool->add('nonexistent_table', ['col' => 'val']);

        $invalidator = new class {
            /** @var list<array<int, string>> The arguments of each invalidate() call. */
            public array $calls = [];

            /**
             * @param  array<int, string>  $tables
             * @return void
             */
            public function invalidate(array $tables): void
            {
                $this->calls[] = $tables;
            }
        };

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')->with(WritePool::class)->andReturn($pool);
        $container->shouldReceive('make')->with(DeferredWriteCacheInvalidator::class)->andReturn($invalidator);

        Log::shouldReceive('warning')->once();

        Event::fake([WritePoolFlushFailed::class]);

        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->handleFlush();

        Event::assertDispatched(WritePoolFlushFailed::class);
        static::assertSame([['users', 'nonexistent_table']], $invalidator->calls);
    }

    /**
     * Subscribe a fresh dispatcher with a pool containing one pending row.
     *
     * @param  string  $email
     * @return array{0: \Illuminate\Events\Dispatcher, 1: \SineMacula\ApiToolkit\Repositories\Concerns\WritePool}
     */
    private function subscribeWithPooledRow(string $email): array
    {
        $pool = new WritePool(500, 10000);
        $pool->add('users', ['name' => 'Dave', 'email' => $email, 'password' => 'secret']);

        $container = \Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with(WritePool::class)
            ->andReturn($pool);

        $events     = new Dispatcher;
        $subscriber = new WritePoolFlushSubscriber($container);

        $subscriber->subscribe($events);

        return [$events, $pool];
    }
}
