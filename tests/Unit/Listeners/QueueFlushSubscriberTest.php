<?php

namespace Tests\Unit\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
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
class QueueFlushSubscriberTest extends TestCase
{
    /**
     * Test that subscribe registers JobProcessed and JobFailed listeners.
     *
     * @return void
     */
    public function testSubscribeRegistersJobProcessedAndJobFailedListeners(): void
    {
        // Arrange
        $cacheManager = $this->app->make(CacheManager::class);        $dispatcher   = \Mockery::mock(Dispatcher::class);
        $subscriber   = new QueueFlushSubscriber($cacheManager);

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
     * Test that handleFlush delegates to CacheManager::flush.
     *
     * @return void
     */
    public function testHandleFlushDelegatesToCacheManager(): void
    {
        // Arrange
        Event::fake();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once();

        $cacheManager = $this->app->make(CacheManager::class);        $subscriber   = new QueueFlushSubscriber($cacheManager);

        $key = 'queue-flush-test';

        Cache::memo()->rememberForever($key, fn () => 'cached');

        static::assertSame('cached', Cache::memo()->get($key));

        // Act
        $subscriber->handleFlush();

        // Assert
        static::assertNull(Cache::memo()->get($key));
    }
}
