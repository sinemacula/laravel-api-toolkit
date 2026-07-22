<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Cross-request state isolation for the long-running Octane runtime.
 *
 * Proves that request-scoped toolkit state does not leak between simulated
 * Octane requests: the scoped ApiQueryParser and WritePool bindings each yield
 * a fresh instance once the per-operation container reset runs, while
 * schema-level singletons (the operator registry and the morph map) survive the
 * boundary that only flushes per-request metadata. A binding regressing to
 * singleton would leak request A's state into request B; an over-aggressive
 * flush would drop the schema-level singletons.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(CacheManager::class)]
#[CoversClass(ContainerBindingRegistrar::class)]
#[CoversClass(MetadataCacheWriter::class)]
#[CoversClass(OctaneFlushListener::class)]
#[CoversClass(OperatorRegistry::class)]
#[CoversClass(RuntimeContext::class)]
#[CoversClass(WritePool::class)]
#[CoversTrait(Deferrable::class)]
final class OctaneRequestIsolationTest extends TestCase
{
    /** @var bool Whether LARAVEL_OCTANE was set before each test. */
    private bool $octaneWasSet;

    /**
     * Capture the initial LARAVEL_OCTANE server state.
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
     * Test that the scoped ApiQueryParser does not leak parsed state across the
     * Octane per-operation boundary.
     *
     * Request A parses a query carrying filters, fields, order, and limit;
     * after the container's scoped-instance reset, request B resolves a fresh
     * parser whose getters all return their empty defaults, proving no residue
     * from request A survives.
     *
     * @return void
     */
    public function testApiQueryParserScopedStateDoesNotLeakAcrossRequests(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $app = $this->app;

        assert($app !== null);

        $alias = config('api-toolkit.parser.alias');

        // Request A: parse a fully-populated query string.
        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parserA */
        $parserA = $app->make($alias);
        $parserA->parse($this->request(
            '/users?filters=' . urlencode('{"name":"Alice"}')
            . '&fields[users]=name&order=name:desc&limit=5',
        ));

        self::assertSame(['name' => 'Alice'], $parserA->getFilters());
        self::assertSame(['name'], $parserA->getFields('users'));
        self::assertSame(['name' => 'desc'], $parserA->getOrder());
        self::assertSame(5, $parserA->getLimit());

        // Boundary: Octane resets scoped instances between operations.
        $app->forgetScopedInstances();

        // Request B: a fresh instance with no query params.
        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parserB */
        $parserB = $app->make($alias);
        $parserB->parse($this->request('/users'));

        self::assertNotSame($parserA, $parserB);
        self::assertSame([], $parserB->getFilters());
        self::assertNull($parserB->getFields('users'));
        self::assertSame([], $parserB->getOrder());
        self::assertNull($parserB->getLimit());
    }

    /**
     * Test that buffered deferred writes do not leak over the Octane boundary.
     *
     * Request A defers a row so the scoped WritePool holds a non-empty buffer.
     * After the boundary flush persists it and the container resets scoped
     * instances, request B resolves a fresh, empty pool that carries none of
     * request A's records, and a request-B deferral flushes only request B's
     * row.
     *
     * @return void
     */
    public function testWritePoolBufferedWritesDoNotLeakAcrossRequests(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $app = $this->app;

        assert($app !== null);

        // Request A: defer a row through the Deferrable repository.
        /** @var \Tests\Fixtures\Repositories\DeferrableUserRepository $repositoryA */
        $repositoryA = $app->make(DeferrableUserRepository::class);
        $repositoryA->defer(['name' => 'Alice', 'email' => 'alice@example.com']);

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $poolA */
        $poolA = $app->make(WritePool::class);

        self::assertFalse($poolA->isEmpty());
        self::assertSame(1, $poolA->count());

        // Boundary: flush the buffer (RequestHandled) then reset scoped
        // instances the way Octane does per operation.
        $poolA->flush();
        $app->forgetScopedInstances();

        self::assertSame(1, DB::table('users')->count());

        // Request B: a fresh, empty pool with none of request A's records.
        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $poolB */
        $poolB = $app->make(WritePool::class);

        self::assertNotSame($poolA, $poolB);
        self::assertTrue($poolB->isEmpty());
        self::assertSame(0, $poolB->count());

        // A request-B deferral flushes only request B's row.
        /** @var \Tests\Fixtures\Repositories\DeferrableUserRepository $repositoryB */
        $repositoryB = $app->make(DeferrableUserRepository::class);
        $repositoryB->defer(['name' => 'Bob', 'email' => 'bob@example.com']);

        self::assertSame(1, $poolB->count());

        $poolB->flush();

        self::assertSame(2, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Alice')->count());
        self::assertSame(1, DB::table('users')->where('name', 'Bob')->count());
    }

    /**
     * Test that schema-level singletons survive the Octane request boundary
     * while per-request metadata is flushed.
     *
     * A custom operator registered on the singleton registry and a morph-map
     * binding are both schema-level state that must outlive the per-request
     * flush; a memoised toolkit metadata key must not. Firing the boundary
     * clears the metadata but leaves the operator and the morph map intact,
     * proving the flush is scoped to per-request caches.
     *
     * @return void
     */
    public function testSchemaLevelSingletonsSurviveBoundaryWhileMetadataIsFlushed(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $app = $this->app;

        assert($app !== null);

        Event::fake();

        // Schema-level state established at boot.
        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry $registry */
        $registry = $app->make(OperatorRegistry::class);
        $registry->register('$starts', $registry->resolve('$like'));

        Relation::morphMap(['users' => User::class]);

        // Per-request metadata memoised through the writer.
        $key = 'integration:octane-schema-survival';
        $this->writer()->rememberMetadataForever($key, static fn () => 'value');

        self::assertSame('value', Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        // Boundary: the Octane flush clears per-request metadata only.
        $this->octaneListener()->handle(new \stdClass);

        self::assertNull(Cache::memo()->get($key)); // @phpstan-ignore method.notFound

        // Schema-level singletons survive: the custom operator and the morph
        // map still resolve on the same singleton instances.
        self::assertSame($registry, $app->make(OperatorRegistry::class));
        self::assertTrue($registry->has('$starts'));
        self::assertSame(User::class, Relation::getMorphedModel('users'));
    }

    /**
     * Build a GET request for the given URI.
     *
     * @param  string  $uri
     * @return \Illuminate\Http\Request
     */
    private function request(string $uri): Request
    {
        return Request::create($uri, 'GET');
    }

    /**
     * Resolve the wired MetadataCacheWriter singleton.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    private function writer(): MetadataCacheWriter
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Cache\MetadataCacheWriter */
        return $this->app->make(MetadataCacheWriter::class);
    }

    /**
     * Build an OctaneFlushListener backed by the wired CacheManager singleton.
     *
     * @return \SineMacula\ApiToolkit\Listeners\OctaneFlushListener
     */
    private function octaneListener(): OctaneFlushListener
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Cache\CacheManager $cacheManager */
        $cacheManager = $this->app->make(CacheManager::class);

        return new OctaneFlushListener($cacheManager, new RuntimeContext);
    }
}
