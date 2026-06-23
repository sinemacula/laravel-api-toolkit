<?php

declare(strict_types = 1);

namespace Tests\Unit\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
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
final class OctaneFlushListenerTest extends TestCase
{
    /** @var bool Whether LARAVEL_OCTANE was set before each test. */
    private bool $octaneWasSet;

    /**
     * Capture the initial LARAVEL_OCTANE state before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->octaneWasSet = isset($_SERVER['LARAVEL_OCTANE']);
    }

    /**
     * Restore the LARAVEL_OCTANE server variable after each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->octaneWasSet) {
            $_SERVER['LARAVEL_OCTANE'] = 1;
        } else {
            unset($_SERVER['LARAVEL_OCTANE']);
        }

        parent::tearDown();
    }

    /**
     * Test that handle flushes toolkit caches when serving under Octane.
     *
     * @return void
     */
    public function testHandleEngagesFlushWhenServingUnderOctane(): void
    {
        // Arrange
        $_SERVER['LARAVEL_OCTANE'] = 1;

        Event::fake();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once();

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class); // @phpstan-ignore method.nonObject

        $key = 'octane-engage-test';
        $registry->register($key);
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        static::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $listener     = new OctaneFlushListener($cacheManager, new RuntimeContext);

        // Act
        $listener->handle(new \stdClass);

        // Assert
        static::assertNull(Cache::memo()->get($key)); // @phpstan-ignore method.notFound
    }

    /**
     * Test that handle does not flush when not serving under Octane (php-fpm).
     *
     * Pins AC-09: a php-fpm process with Octane installed must not flush on
     * every request.
     *
     * @return void
     */
    public function testHandleDoesNotEngageFlushUnderPhpFpm(): void
    {
        // Arrange
        unset($_SERVER['LARAVEL_OCTANE']);

        Event::fake();

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class); // @phpstan-ignore method.nonObject

        $key = 'octane-no-flush-test';
        $registry->register($key);
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        static::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $listener     = new OctaneFlushListener($cacheManager, new RuntimeContext);

        // Act
        $listener->handle(new \stdClass);

        // Assert
        static::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
    }

    /**
     * Test that handle delegates to CacheManager::flush when serving under
     * Octane.
     *
     * @return void
     */
    public function testHandleDelegatesToCacheManager(): void
    {
        // Arrange
        $_SERVER['LARAVEL_OCTANE'] = 1;

        Event::fake();

        $this->mock(SchemaIntrospectionProvider::class)
            ->shouldReceive('flush')
            ->once();

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class); // @phpstan-ignore method.nonObject

        $key = 'octane-flush-test';
        $registry->register($key);
        Cache::memo()->rememberForever($key, fn () => 'cached'); // @phpstan-ignore method.notFound

        static::assertSame('cached', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        $cacheManager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $listener     = new OctaneFlushListener($cacheManager, new RuntimeContext);

        // Act
        $listener->handle(new \stdClass);

        // Assert
        static::assertNull(Cache::memo()->get($key)); // @phpstan-ignore method.notFound
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
        $_SERVER['LARAVEL_OCTANE'] = 1;

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
        $listener     = new OctaneFlushListener($cacheManager, new RuntimeContext);

        $listener->handle(new \stdClass);
    }
}
