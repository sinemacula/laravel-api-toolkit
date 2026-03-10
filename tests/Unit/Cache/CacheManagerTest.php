<?php

namespace Tests\Unit\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Http\Resources\Concerns\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * Tests for the CacheManager service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheManager::class)]
class CacheManagerTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that flush clears the memo cache.
     *
     * @return void
     */
    public function testFlushClearsMemoCache(): void
    {
        // Arrange
        Event::fake();

        $key = 'test-memo-key';

        Cache::memo()->rememberForever($key, fn () => 'cached-value');

        static::assertSame('cached-value', Cache::memo()->get($key));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        static::assertNull(Cache::memo()->get($key));
    }

    /**
     * Test that flush clears the SchemaCompiler static cache.
     *
     * @return void
     */
    public function testFlushClearsSchemaCompilerCache(): void
    {
        // Arrange
        Event::fake();

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['FakeResource' => 'compiled']);

        static::assertNotEmpty($this->getStaticProperty(SchemaCompiler::class, 'cache'));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        static::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
    }

    /**
     * Test that flush clears the SchemaIntrospector singleton state.
     *
     * @return void
     */
    public function testFlushClearsSchemaIntrospectorState(): void
    {
        // Arrange
        Event::fake();

        $mock = $this->mock(SchemaIntrospectionProvider::class);

        $mock->shouldReceive('flush')
            ->once();

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert (handled by Mockery expectation)
    }

    /**
     * Test that flush dispatches the CacheFlushed event.
     *
     * @return void
     */
    public function testFlushDispatchesCacheFlushedEvent(): void
    {
        // Arrange
        Event::fake();

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that flush on empty state does not throw an exception.
     *
     * @return void
     */
    public function testFlushOnEmptyStateIsHarmless(): void
    {
        // Arrange
        Event::fake();

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        static::assertNull(Cache::memo()->get('nonexistent'));
        static::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
        Event::assertDispatched(CacheFlushed::class);
    }
}
