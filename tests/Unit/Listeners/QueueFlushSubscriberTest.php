<?php

declare(strict_types = 1);

namespace Tests\Unit\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use Tests\Concerns\SwapsExceptionHandler;
use Tests\TestCase;

/**
 * Tests for the QueueFlushSubscriber.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueueFlushSubscriber::class)]
final class QueueFlushSubscriberTest extends TestCase
{
    use SwapsExceptionHandler;

    /**
     * Test that subscribe registers JobProcessed and JobFailed listeners.
     *
     * @return void
     */
    public function testSubscribeRegistersJobProcessedAndJobFailedListeners(): void
    {
        // Arrange
        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $dispatcher   = \Mockery::mock(Dispatcher::class);
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);

        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(JobProcessed::class, [$subscriber, 'handleFlush']);

        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(JobFailed::class, [$subscriber, 'handleFlush']);

        // Act
        $subscriber->subscribe($dispatcher);
    }

    /**
     * Test that handleFlush resets in-process state for a real worker
     * connection while the shared metadata stays warm.
     *
     * @return void
     */
    public function testHandleFlushEngagesOnRealWorkerConnection(): void
    {
        // Arrange
        Config::set('queue.connections.database.driver', 'database');

        Event::fake();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once();

        $key = 'queue-engage-test';
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);
        $event        = new JobProcessed('database', self::createStub(Job::class));

        // Act
        $subscriber->handleFlush($event);

        // Assert
        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that handleFlush does not flush for a sync connection (in-request
     * job).
     *
     * Sync jobs run within the HTTP request and must not trigger a flush at the
     * job boundary.
     *
     * @return void
     */
    public function testHandleFlushDoesNotEngageOnSyncConnection(): void
    {
        // Arrange
        Config::set('queue.connections.sync.driver', 'sync');

        Event::fake();

        $key = 'queue-no-flush-test';
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);
        $event        = new JobProcessed('sync', self::createStub(Job::class));

        // Act
        $subscriber->handleFlush($event);

        // Assert
        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
        Event::assertNotDispatched(CacheFlushed::class);
    }

    /**
     * Test that handleFlush delegates to CacheManager::flush for a non-sync
     * connection.
     *
     * @return void
     */
    public function testHandleFlushDelegatesToCacheManager(): void
    {
        // Arrange
        Config::set('queue.connections.database.driver', 'database');

        Event::fake();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once();

        $key = 'queue-flush-test';
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);
        $event        = new JobProcessed('database', self::createStub(Job::class));

        // Act
        $subscriber->handleFlush($event);

        // Assert
        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that a CacheManager flush failure is caught and reported through the
     * exception handler rather than propagating and crashing the worker.
     *
     * @return void
     */
    public function testHandleFlushReportsCacheFlushFailure(): void
    {
        Config::set('queue.connections.database.driver', 'database');

        Event::fake();
        Exceptions::fake();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once()
            ->andThrow(new \RuntimeException('flush boom'));

        Log::shouldReceive('error')->never();

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);
        $event        = new JobProcessed('database', self::createStub(Job::class));

        $subscriber->handleFlush($event);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e->getMessage() === 'flush boom');
    }

    /**
     * Test that when reporting the flush failure itself fails, the original
     * failure falls back to the error log and the worker is not crashed.
     *
     * @return void
     */
    public function testHandleFlushFallsBackToLogWhenReportingFails(): void
    {
        Config::set('queue.connections.database.driver', 'database');

        Event::fake();
        $handler = $this->useThrowingExceptionHandler();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once()
            ->andThrow(new \RuntimeException('flush boom'));

        Log::shouldReceive('error')
            ->once()
            ->with('Queue worker cache flush failed', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException
                    && $context['exception']->getMessage() === 'flush boom',
            ));

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);
        $event        = new JobProcessed('database', self::createStub(Job::class));

        $subscriber->handleFlush($event);

        self::assertCount(1, $handler->reported);
    }
}
