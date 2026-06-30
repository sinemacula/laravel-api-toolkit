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
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
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
     * Test that handleFlush flushes toolkit caches for a real worker
     * connection.
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

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class); // @phpstan-ignore method.nonObject

        $key = 'queue-engage-test';
        $registry->register($key);
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);
        $event        = new JobProcessed('database', self::createStub(Job::class));

        // Act
        $subscriber->handleFlush($event);

        // Assert
        self::assertNull(Cache::memo()->get($key)); // @phpstan-ignore method.notFound
    }

    /**
     * Test that handleFlush does not flush for a sync connection (in-request
     * job).
     *
     * Pins AC-06: sync jobs run within the HTTP request and must not trigger a
     * metadata flush at the job boundary.
     *
     * @return void
     */
    public function testHandleFlushDoesNotEngageOnSyncConnection(): void
    {
        // Arrange
        Config::set('queue.connections.sync.driver', 'sync');

        Event::fake();

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class); // @phpstan-ignore method.nonObject

        $key = 'queue-no-flush-test';
        $registry->register($key);
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);
        $event        = new JobProcessed('sync', self::createStub(Job::class));

        // Act
        $subscriber->handleFlush($event);

        // Assert
        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
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

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class); // @phpstan-ignore method.nonObject

        $key = 'queue-flush-test';
        $registry->register($key);
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        self::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $subscriber   = new QueueFlushSubscriber($cacheManager, new RuntimeContext);
        $event        = new JobProcessed('database', self::createStub(Job::class));

        // Act
        $subscriber->handleFlush($event);

        // Assert
        self::assertNull(Cache::memo()->get($key)); // @phpstan-ignore method.notFound
    }

    /**
     * Test that a CacheManager flush failure inside a queue worker is caught
     * and logged with the full throwable rather than propagating and failing
     * the job boundary.
     *
     * @return void
     */
    public function testHandleFlushSwallowsAndLogsCacheFlushFailure(): void
    {
        // Arrange
        Config::set('queue.connections.database.driver', 'database');

        Event::fake();

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

        // Act
        $subscriber->handleFlush($event);
    }
}
