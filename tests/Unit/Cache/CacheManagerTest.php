<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
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
final class CacheManagerTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that flush forgets a registered toolkit memo key.
     *
     * @return void
     */
    public function testFlushClearsMemoCache(): void
    {
        // Arrange
        Event::fake();

        $key = 'test-memo-key';

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class); // @phpstan-ignore method.nonObject

        $registry->register($key);

        Cache::memo()->rememberForever($key, fn () => 'cached-value'); // @phpstan-ignore method.notFound

        self::assertSame('cached-value', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        // Act
        /** @var \SineMacula\ApiToolkit\Cache\CacheManager $manager */
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertNull(Cache::memo()->get($key)); // @phpstan-ignore method.notFound
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

        self::assertNotEmpty($this->getStaticProperty(SchemaCompiler::class, 'cache'));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
    }

    /**
     * Test that flush clears the ValueResolver serialization memo caches.
     *
     * @return void
     */
    public function testFlushClearsValueResolverCache(): void
    {
        // Arrange
        Event::fake();

        $this->setStaticProperty(ValueResolver::class, 'castAccessorCache', ['FakeModel' => ['field' => true]]);

        self::assertNotEmpty($this->getStaticProperty(ValueResolver::class, 'castAccessorCache'));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertSame([], $this->getStaticProperty(ValueResolver::class, 'castAccessorCache'));
    }

    /**
     * Test that flush clears the EagerLoadPlanner static memo caches.
     *
     * @return void
     */
    public function testFlushClearsEagerLoadPlannerCache(): void
    {
        // Arrange
        Event::fake();

        $this->setStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache', ['FakeResource|fields' => ['relation']]);

        self::assertNotEmpty($this->getStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache'));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertSame([], $this->getStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache'));
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
     * Test that flush resets the bound query parser state.
     *
     * @return void
     */
    public function testFlushResetsBoundQueryParser(): void
    {
        // Arrange
        Event::fake();

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query'); // @phpstan-ignore method.nonObject

        $parser->parse(Request::create('/test', 'GET', ['fields' => 'name,email']));

        self::assertSame(['name', 'email'], $parser->getFields());

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertNull($parser->getFields());
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
        self::assertNull(Cache::memo()->get('nonexistent')); // @phpstan-ignore method.notFound
        self::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that flush leaves an unregistered non-toolkit key intact.
     *
     * Pins NFR-01/AC-08: keys written directly to the memo store without going
     * through the MetadataKeyRegistry must survive the scoped flush.
     *
     * @return void
     */
    public function testFlushLeavesUnregisteredNonToolkitKeyIntact(): void
    {
        // Arrange
        Event::fake();

        Cache::memo()->rememberForever('non-toolkit-key', fn () => 'survivor'); // @phpstan-ignore method.notFound

        // Act
        /** @var \SineMacula\ApiToolkit\Cache\CacheManager $manager */
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertSame('survivor', Cache::memo()->get('non-toolkit-key')); // @phpstan-ignore method.notFound
    }

    /**
     * Test that flush forgets all registered toolkit keys and empties the
     * registry.
     *
     * @return void
     */
    public function testFlushForgetsRegisteredToolkitKeysAndClearsRegistry(): void
    {
        // Arrange
        Event::fake();

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class); // @phpstan-ignore method.nonObject

        $registry->register('toolkit-key-one');
        $registry->register('toolkit-key-two');

        Cache::memo()->rememberForever('toolkit-key-one', fn () => 'value-one'); // @phpstan-ignore method.notFound
        Cache::memo()->rememberForever('toolkit-key-two', fn () => 'value-two'); // @phpstan-ignore method.notFound

        // Act
        /** @var \SineMacula\ApiToolkit\Cache\CacheManager $manager */
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertNull(Cache::memo()->get('toolkit-key-one')); // @phpstan-ignore method.notFound
        self::assertNull(Cache::memo()->get('toolkit-key-two')); // @phpstan-ignore method.notFound
        self::assertSame([], $registry->keys());
    }
}
