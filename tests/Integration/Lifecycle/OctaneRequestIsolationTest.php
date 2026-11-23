<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\CallerAdmission;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ScopeApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Gathering;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\GatheringResource;
use Tests\Fixtures\Resources\UserResource;
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
#[CoversClass(CallerAdmission::class)]
#[CoversClass(ContainerBindingRegistrar::class)]
#[CoversClass(MetadataCacheWriter::class)]
#[CoversClass(OctaneFlushListener::class)]
#[CoversClass(OperatorRegistry::class)]
#[CoversClass(RuntimeContext::class)]
#[CoversClass(ScopeApplier::class)]
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

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class]);

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
     * Test that schema-level singletons already resolved on the base
     * application, and the shared metadata, survive a boundary.
     *
     * @return void
     */
    public function testSchemaLevelSingletonsResolvedOnTheBaseAppAndSharedMetadataSurviveBoundary(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $app = $this->app;

        assert($app !== null);

        Event::fake();

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry $registry */
        $registry = $app->make(OperatorRegistry::class);
        $equal    = $registry->resolve('$eq');

        self::assertNotNull($equal);

        $registry->register('$starts', $equal, Capability::RANGE);

        Relation::morphMap(['users' => User::class]);

        $key = 'integration:octane-schema-survival';
        $this->writer()->storeMetadata($key, 'value');

        $this->octaneListener()->handle(new \stdClass);

        self::assertSame('value', $this->writer()->readMetadata($key));

        self::assertSame($registry, $app->make(OperatorRegistry::class));
        self::assertTrue($registry->admits('$starts', Capability::RANGE));
        self::assertSame(User::class, Relation::getMorphedModel('users'));

        $app->make(CacheManager::class)->invalidateMetadata();

        self::assertNull($this->writer()->readMetadata($key));
        self::assertSame('rebuilt', $this->writer()->rememberMetadata($key, static fn () => 'rebuilt', 60));
    }

    /**
     * Test that successive requests through one compiled scope each get their
     * own caller admission, rows and argument errors.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testSuccessiveRequestsThroughOneCompiledScopeKeepTheirOwnCallerRowsAndErrors(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        Gathering::create(['name' => 'Launch', 'user_id' => $alice->id, 'starts_at' => now(), 'latitude' => 51.51, 'longitude' => -0.13]);
        Gathering::create(['name' => 'Retro', 'user_id' => $bob->id, 'starts_at' => now(), 'latitude' => 48.85, 'longitude' => 2.35]);

        $scope   = SchemaCompiler::compile(GatheringResource::class)->getScopes()['near'];
        $london  = ['$scope' => ['near' => ['lat' => 51.5, 'lng' => -0.12, 'km' => 5]]];
        $refused = ['filters.$scope.near.lat' => ['The lat field must be between -90 and 90.']];
        $outside = ['$scope' => ['near' => ['lat' => 91, 'lng' => -0.12, 'km' => 5]]];

        self::assertSame(['Retro'], $this->serve($bob, ['$scope' => 'mine']));
        self::assertSame(['filters.$scope.mine' => ['The "mine" scope is not declared for this resource.']], $this->serve(null, ['$scope' => 'mine']));
        self::assertSame(['Launch'], $this->serve($alice, ['$scope' => 'mine']));
        self::assertSame($refused, $this->serve($alice, $outside));
        self::assertSame(['Launch'], $this->serve($bob, $london));
        self::assertSame($refused, $this->serve(null, $outside));
        self::assertSame($scope, SchemaCompiler::compile(GatheringResource::class)->getScopes()['near']);
    }

    /**
     * Serve one gathering request as the given caller, returning the names it
     * reads or the errors it is refused with.
     *
     * @param  \Tests\Fixtures\Models\User|null  $caller
     * @param  array<string, mixed>  $filters
     * @return array<array-key, mixed>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function serve(?User $caller, array $filters): array
    {
        $app = $this->app;

        assert($app !== null);

        $app->forgetScopedInstances();
        $app->make(AuthManager::class)->forgetGuards();

        if ($caller !== null) {
            $this->actingAs($caller);
        }

        $request = Request::create('/gatherings?filters=' . urlencode(json_encode($filters, JSON_THROW_ON_ERROR)));

        $app->instance('request', $request);

        ApiQuery::parse($request);

        try {
            /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
            $criteria = $app->make(ApiCriteria::class);

            return $criteria->usingResource(GatheringResource::class)->apply(new Gathering)->orderBy('name')->pluck('name')->all();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
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
