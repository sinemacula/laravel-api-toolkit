<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Exceptions\MetadataInvalidationException;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIdentity;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchPlan;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\User;
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
     * Test that flush leaves toolkit metadata in the shared store, so every
     * worker keeps serving what any worker has already read.
     *
     * @return void
     */
    public function testFlushLeavesSharedMetadataInTheStore(): void
    {
        Event::fake();

        $key = $this->metadataStorageKey('shared-metadata-key');

        Cache::memo()->rememberForever($key, fn (): string => 'cached-value'); // @phpstan-ignore method.notFound

        $this->manager()->flush();

        self::assertSame('cached-value', Cache::memo()->get($key)); // @phpstan-ignore method.notFound
        self::assertSame('cached-value', Cache::store()->get($key));
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
     * Test that flush clears the FieldResolver assembled-field memo.
     *
     * @return void
     */
    public function testFlushClearsFieldResolverCache(): void
    {
        // Arrange
        Event::fake();

        $this->setStaticProperty(FieldResolver::class, 'resolvedCache', ['name\0email|||' => ['name', 'email']]);

        self::assertNotEmpty($this->getStaticProperty(FieldResolver::class, 'resolvedCache'));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertSame([], $this->getStaticProperty(FieldResolver::class, 'resolvedCache'));
    }

    /**
     * Test that flush clears the FieldColumnMapper static cache.
     *
     * @return void
     */
    public function testFlushClearsFieldColumnMapperCache(): void
    {
        // Arrange
        Event::fake();

        $this->setStaticProperty(FieldColumnMapper::class, 'cache', ['FakeResource' => 'map']);

        self::assertNotEmpty($this->getStaticProperty(FieldColumnMapper::class, 'cache'));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertSame([], $this->getStaticProperty(FieldColumnMapper::class, 'cache'));
    }

    /**
     * Test that flush clears the SearchPlan static cache, so a compiled search
     * surface cannot outlive the schema it was built from.
     *
     * @return void
     */
    public function testFlushClearsSearchPlanCache(): void
    {
        // Arrange
        Event::fake();

        $this->setStaticProperty(SearchPlan::class, 'cache', ['FakeResource' => 'plan']);

        self::assertNotEmpty($this->getStaticProperty(SearchPlan::class, 'cache'));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertSame([], $this->getStaticProperty(SearchPlan::class, 'cache'));
    }

    /**
     * Test that flush clears the IndexProof static cache, so a proof taken
     * against one catalogue cannot answer for the next.
     *
     * @return void
     */
    public function testFlushClearsIndexProofCache(): void
    {
        // Arrange
        Event::fake();

        $this->setStaticProperty(IndexProof::class, 'cache', ['sqlite|sqlite|users|substring|name' => ['defect']]);

        self::assertNotEmpty($this->getStaticProperty(IndexProof::class, 'cache'));

        // Act
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
        $manager->flush();

        // Assert
        self::assertSame([], $this->getStaticProperty(IndexProof::class, 'cache'));
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     * Test that flush skips the query parser reset when the configured alias is
     * not bound in the container.
     *
     * @return void
     */
    public function testFlushSkipsQueryParserResetWhenAliasNotBound(): void
    {
        // Arrange
        Event::fake();

        config()->set('api-toolkit.parser.alias', 'api.query.unbound');

        assert($this->app !== null);
        self::assertFalse($this->app->bound('api.query.unbound'));

        // Act
        /** @var \SineMacula\ApiToolkit\Cache\CacheManager $manager */
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
        self::assertNull(Cache::memo()->get('nonexistent')); // @phpstan-ignore method.notFound
        self::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that flush leaves a non-toolkit key on the same store intact, so a
     * boundary never clears the store it shares with the application.
     *
     * @return void
     */
    public function testFlushLeavesNonToolkitKeyIntact(): void
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
     * Test that schema metadata an earlier process wrote is still served after
     * a boundary, without the connection being asked again.
     *
     * @return void
     */
    public function testFlushKeepsServingSchemaMetadataAnEarlierProcessWrote(): void
    {
        Event::fake();

        $key = $this->metadataStorageKey(CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([SchemaIdentity::of(DB::connection('testing')), User::class]));

        Cache::memo()->rememberForever($key, fn (): array => ['id', 'name']); // @phpstan-ignore method.notFound

        $this->manager()->flush();

        /** @var \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider $introspector */
        $introspector = $this->app->make(SchemaIntrospectionProvider::class); // @phpstan-ignore method.nonObject

        self::assertSame(['id', 'name'], $introspector->getColumns(new User));
    }

    /**
     * Test that a flush keeps the generation, so a boundary in one worker never
     * retires the metadata every other worker is serving.
     *
     * @return void
     */
    public function testFlushDoesNotAdvanceTheGeneration(): void
    {
        Event::fake();

        $generation = $this->generation();
        $before     = $generation->current();

        $this->manager()->flush();

        self::assertSame($before, $generation->current());
        self::assertSame($before, Cache::get(CacheKeys::METADATA_GENERATION->resolveKey()));
    }

    /**
     * Test that a flush drops the memoised generation, so a long-lived worker
     * picks up an invalidation another process made at its next boundary.
     *
     * @return void
     */
    public function testFlushRereadsAGenerationReplacedElsewhere(): void
    {
        Event::fake();

        $generation = $this->generation();
        $before     = $generation->current();

        $elsewhere = (new MetadataGeneration)->advance();

        self::assertSame($before, $generation->current());

        $this->manager()->flush();

        self::assertSame($elsewhere, $generation->current());
    }

    /**
     * Test that invalidating replaces the generation in the store and in this
     * process.
     *
     * @return void
     */
    public function testInvalidateMetadataReplacesTheGeneration(): void
    {
        Event::fake();

        $generation = $this->generation();
        $before     = $generation->current();

        $this->manager()->invalidateMetadata();

        $after = Cache::get(CacheKeys::METADATA_GENERATION->resolveKey());

        self::assertIsString($after);
        self::assertNotSame($before, $after);
        self::assertSame($after, $generation->current());
    }

    /**
     * Test that a store rejecting the new generation is surfaced before this
     * process is flushed, so nothing reports an invalidation that did not
     * happen.
     *
     * @return void
     */
    public function testInvalidateMetadataSurfacesARejectedGeneration(): void
    {
        Event::fake();

        $this->useRejectingCacheStore();

        try {
            $this->manager()->invalidateMetadata();
            self::fail('A rejected generation was reported as invalidated.');
        } catch (MetadataInvalidationException $exception) {
            self::assertSame('The cache store rejected the new metadata generation, so the cached metadata was not invalidated.', $exception->getMessage());
        }

        Event::assertNotDispatched(CacheFlushed::class);
    }

    /**
     * Test that invalidating also flushes this process: metadata written under
     * the previous generation is no longer read, memos are cleared, and the
     * flushed event is dispatched.
     *
     * @return void
     */
    public function testInvalidateMetadataFlushesThisProcess(): void
    {
        Event::fake();

        /** @var \SineMacula\ApiToolkit\Cache\MetadataCacheWriter $writer */
        $writer = $this->app->make(MetadataCacheWriter::class); // @phpstan-ignore method.nonObject

        $writer->rememberMetadataForever('invalidated-key', fn (): string => 'stale');
        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['FakeResource' => 'compiled']);

        $this->manager()->invalidateMetadata();

        self::assertNull($writer->readMetadata('invalidated-key'));
        self::assertSame([], $this->getStaticProperty(SchemaCompiler::class, 'cache'));
        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that metadata an earlier process wrote is retired by a process that
     * never touched it, and the next read recomputes it.
     *
     * A flush leaves the shared store alone, so only replacing the generation
     * reaches an entry the writing process left behind.
     *
     * @return void
     */
    public function testInvalidateMetadataRetiresMetadataAnotherProcessWrote(): void
    {
        Event::fake();

        $key = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([SchemaIdentity::of(DB::connection('testing')), User::class]);

        $earlier = new MetadataCacheWriter(new MetadataGeneration);
        $earlier->rememberMetadataForever($key, fn (): array => ['stale-column']);

        $this->generation()->forget();

        $this->manager()->invalidateMetadata();

        $later = new MetadataCacheWriter(new MetadataGeneration);

        self::assertNull($later->readMetadata($key));

        /** @var \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider $introspector */
        $introspector = $this->app->make(SchemaIntrospectionProvider::class); // @phpstan-ignore method.nonObject

        self::assertContains('email', $introspector->getColumns(new User));
        self::assertNotContains('stale-column', $introspector->getColumns(new User));
    }

    /**
     * Resolve the container's cache manager.
     *
     * @return \SineMacula\ApiToolkit\Cache\CacheManager
     */
    private function manager(): CacheManager
    {
        /** @var \SineMacula\ApiToolkit\Cache\CacheManager */
        return $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject
    }

    /**
     * Resolve the container's metadata generation.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataGeneration
     */
    private function generation(): MetadataGeneration
    {
        /** @var \SineMacula\ApiToolkit\Cache\MetadataGeneration */
        return $this->app->make(MetadataGeneration::class); // @phpstan-ignore method.nonObject
    }
}
