<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
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
     * Test that the scoped ApiQueryParser does not leak state across requests.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApiQueryParserScopedStateDoesNotLeakAcrossRequests(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $app = $this->app;

        assert($app !== null);

        $alias = config('api-toolkit.parser.alias');

        self::assertIsString($alias);

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

        // Octane resets scoped instances between operations
        $app->forgetScopedInstances();

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
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testWritePoolBufferedWritesDoNotLeakAcrossRequests(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $app = $this->app;

        assert($app !== null);

        /** @var \Tests\Fixtures\Repositories\DeferrableUserRepository $repositoryA */
        $repositoryA = $app->make(DeferrableUserRepository::class);
        $repositoryA->defer(['name' => 'Alice', 'email' => 'alice@example.com']);

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $poolA */
        $poolA = $app->make(WritePool::class);

        self::assertFalse($poolA->isEmpty());
        self::assertSame(1, $poolA->count());

        // Flush as RequestHandled would, then reset as Octane does
        $poolA->flush();
        $app->forgetScopedInstances();

        self::assertSame(1, DB::table('users')->count());

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $poolB */
        $poolB = $app->make(WritePool::class);

        self::assertNotSame($poolA, $poolB);
        self::assertTrue($poolB->isEmpty());
        self::assertSame(0, $poolB->count());

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
     * Test that schema-level singletons and shared metadata survive a boundary.
     *
     * @return void
     */
    public function testSchemaLevelSingletonsAndSharedMetadataSurviveBoundary(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $app = $this->app;

        assert($app !== null);

        Event::fake();

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry $registry */
        $registry = $app->make(OperatorRegistry::class);
        $equal    = $registry->resolve('$eq');

        self::assertNotNull($equal);

        $registry->register('$starts', $equal);

        Relation::morphMap(['users' => User::class]);

        $key = 'integration:octane-schema-survival';
        $this->writer()->rememberMetadataForever($key, static fn () => 'value');

        $this->octaneListener()->handle(new \stdClass);

        self::assertSame('value', $this->writer()->readMetadata($key));

        self::assertSame($registry, $app->make(OperatorRegistry::class));
        self::assertTrue($registry->has('$starts'));
        self::assertSame(User::class, Relation::getMorphedModel('users'));

        $app->make(CacheManager::class)->invalidateMetadata();

        self::assertNull($this->writer()->readMetadata($key));
        self::assertSame('rebuilt', $this->writer()->rememberMetadataForever($key, static fn () => 'rebuilt'));
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
