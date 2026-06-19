<?php

namespace Tests\Unit\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use Tests\TestCase;

/**
 * Tests for the OctaneFlushListener.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OctaneFlushListener::class)]
class OctaneFlushListenerTest extends TestCase
{
    /**
     * Test that handle delegates to CacheManager::flush.
     *
     * @return void
     */
    public function testHandleDelegatesToCacheManager(): void
    {
        // Arrange
        Event::fake();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once();

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $listener     = new OctaneFlushListener($cacheManager);

        $key = 'octane-flush-test';

        Cache::memo()->rememberForever($key, fn () => 'cached');

        static::assertSame('cached', Cache::memo()->get($key));

        // Act
        $listener->handle(new \stdClass);

        // Assert
        static::assertNull(Cache::memo()->get($key));
    }

    /**
     * Test that a CacheManager flush failure is caught and logged with the full
     * throwable rather than propagating into Octane's event dispatch and
     * crashing the worker.
     *
     * @return void
     */
    public function testHandleSwallowsAndLogsCacheFlushFailure(): void
    {
        Event::fake();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once()
            ->andThrow(new \RuntimeException('flush boom'));

        Log::shouldReceive('error')
            ->once()
            ->with('Octane cache flush failed', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException
                    && $context['exception']->getMessage() === 'flush boom',
            ));

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $listener     = new OctaneFlushListener($cacheManager);

        $listener->handle(new \stdClass);
    }
}
