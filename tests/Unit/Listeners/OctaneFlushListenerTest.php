<?php

namespace Tests\Unit\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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

        $cacheManager = $this->app->make(CacheManager::class);        $listener     = new OctaneFlushListener($cacheManager);

        $key = 'octane-flush-test';

        Cache::memo()->rememberForever($key, fn () => 'cached');

        static::assertSame('cached', Cache::memo()->get($key));

        // Act
        $listener->handle(new \stdClass);

        // Assert
        static::assertNull(Cache::memo()->get($key));
    }
}
