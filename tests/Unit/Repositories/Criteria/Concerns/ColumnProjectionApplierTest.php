<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\MeteredPostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ColumnProjectionApplier concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnProjectionApplier::class)]
final class ColumnProjectionApplierTest extends TestCase
{
    /** @var array<int, string> */
    private const array USER_DEFAULT_FIELDS = ['id', 'name', 'email'];

    /** @var array<int, string> */
    private const array USER_COLUMNS = ['id', 'organization_id', 'name', 'email', 'password', 'status', 'created_at', 'updated_at'];

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class]);

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that apply returns the query unchanged when the narrow flag is off.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFlagOffReturnsQueryWithoutSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(self::never())->method('resolveFields');
        $provider->expects(self::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        self::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that apply returns the query unchanged for a null resource class.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNullResourceClassReturnsQueryWithoutSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(self::never())->method('resolveFields');
        $provider->expects(self::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, null, []);

        self::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that apply returns the query unchanged for a non-ApiResource class.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNonApiResourceClassReturnsQueryWithoutSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(self::never())->method('resolveFields');
        $provider->expects(self::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, \stdClass::class, []);

        self::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that apply leaves the query unchanged for an empty field set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testEmptyResolvedFieldsReturnsQueryWithoutSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        self::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that a fall-back decision applies no select.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFallbackDecisionAppliesNoSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(['id', 'full_label']);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        self::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that a fall-back forced by an unmapped field is logged at debug.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFallbackLogsOffendingFieldAtDebugLevel(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(['id', 'full_label']);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Column narrowing fell back to a full select'
                    && $context['model']                                      === User::class
                    && $context['resource']                                   === UserResource::class
                    && $context['field']                                      === 'full_label');

        $this->makeApplier()->apply((new User)->newQuery(), $provider, UserResource::class, []);
    }

    /**
     * Test that a narrow decision applies the select once with its columns.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNarrowDecisionAppliesSelectOnce(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, ['name' => 'asc']);

        self::assertSame(['users.id', 'users.name', 'users.email'], $result->getQuery()->columns);
    }

    /**
     * Test that the declared fixed fields join the narrowed select, and an
     * undeclared one such as `_type` reads no column.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredFixedFieldsJoinTheNarrowedSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(['name']);
        $provider->method('getAllFields')->willReturn(['id', 'name', 'email', 'status']);
        $provider->method('getFixedFields')->willReturn(['id', '_type', 'status']);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        self::assertSame(['users.name', 'users.id', 'users.status'], $result->getQuery()->columns);
    }

    /**
     * Test that a field the introspected table lacks falls back, logging it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFallsBackForAFieldMissingFromTheIntrospectedColumns(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $query = (new User)->newQuery();

        $introspector = $this->createMock(SchemaIntrospectionProvider::class);
        $introspector->expects(self::once())->method('getColumns')->with($query->getModel())->willReturn(['id', 'name']);

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['field'] === 'email');

        $applier = new ColumnProjectionApplier(new SafetySetDeriver($this->makeIntrospector()), $introspector);

        self::assertNull($applier->apply($query, $provider, UserResource::class, [])->getQuery()->columns);
    }

    /**
     * Test that the ':all' token resolves the full declared field set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAllFieldsTokenResolvesViaGetAllFields(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request(['fields' => ['users' => ':all']]));

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('getAllFields')->willReturn(['id', 'name']);
        $provider->method('resolveFields')->willReturn(['id', 'email']);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        self::assertSame(['users.id', 'users.name'], $result->getQuery()->columns);
    }

    /**
     * Test that the parent key of every list-keyed relation is retained.
     *
     * A list entry carries the relation name in its value, not its key.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRetainsParentKeysForEveryListKeyedRelation(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        $provider->method('eagerLoadMapFor')->willReturn(['author', 'editor']);

        $author = self::createStub(Relation::class);
        $editor = self::createStub(Relation::class);

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn([...self::USER_COLUMNS, 'author_id', 'editor_id']);
        $introspector->method('getDeletedAtColumn')->willReturn(null);
        $introspector->method('resolveRelation')->willReturnCallback(
            static fn (string $key): ?Relation => match ($key) {
                'author' => $author,
                'editor' => $editor,
                default  => null,
            },
        );
        $introspector->method('parentKeysFor')->willReturnCallback(
            static fn (Relation $relation): array => $relation === $author ? ['author_id'] : ['editor_id'],
        );

        $applier = new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector);

        $columns = $applier->apply((new User)->newQuery(), $provider, UserResource::class, [])->getQuery()->columns;

        self::assertNotNull($columns);
        self::assertContains('users.author_id', $columns);
        self::assertContains('users.editor_id', $columns);
    }

    /**
     * Test that a dotted relation path resolves its first segment's parent key.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testStripsDottedPathToBaseRelationForParentKey(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        $provider->method('eagerLoadMapFor')->willReturn(['posts.comments']);

        $relation = self::createStub(Relation::class);

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn(self::USER_COLUMNS);
        $introspector->method('getDeletedAtColumn')->willReturn(null);
        $introspector->method('resolveRelation')->willReturnCallback(
            static fn (string $key): ?Relation => $key === 'posts' ? $relation : null,
        );
        $introspector->method('parentKeysFor')->willReturn(['organization_id']);

        $applier = new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector);

        $columns = $applier->apply((new User)->newQuery(), $provider, UserResource::class, [])->getQuery()->columns;

        self::assertNotNull($columns);
        self::assertContains('users.organization_id', $columns);
    }

    /**
     * Test that an eager-load entry with a non-string relation path is skipped.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testSkipsNonStringRelationPathInEagerLoadMap(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        // A nested array where a dot-path string is expected
        $provider->method('eagerLoadMapFor')->willReturn([['nested', 'array']]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        $columns = $result->getQuery()->columns;

        self::assertNotNull($columns);
        self::assertContains('users.id', $columns);
        self::assertContains('users.name', $columns);
        self::assertContains('users.email', $columns);
    }

    /**
     * Test that an absent narrowing flag fails rather than reading as disabled,
     * which a published section predating it would otherwise do.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAbsentNarrowFlagFails(): void
    {
        Config::set('api-toolkit.resources', []);

        $this->parseRequest(new Request);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.resources.narrow_columns setting is missing; add it from the published config.');

        $this->makeApplier()->apply((new User)->newQuery(), self::createStub(ResourceMetadataProvider::class), UserResource::class, ['name' => 'asc']);
    }

    /**
     * Test that a non-string relation path does not stop later relations.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testProcessesListKeyedRelationAfterNonStringPath(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        // A non-string path precedes a valid list-keyed relation
        $provider->method('eagerLoadMapFor')->willReturn([['nested', 'array'], 'author']);

        $author = self::createStub(Relation::class);

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn([...self::USER_COLUMNS, 'author_id']);
        $introspector->method('getDeletedAtColumn')->willReturn(null);
        $introspector->method('resolveRelation')->willReturnCallback(
            static fn (string $key): ?Relation => $key === 'author' ? $author : null,
        );
        $introspector->method('parentKeysFor')->willReturn(['author_id']);

        $applier = new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector);

        $columns = $applier->apply((new User)->newQuery(), $provider, UserResource::class, [])->getQuery()->columns;

        self::assertNotNull($columns);
        self::assertContains('users.author_id', $columns);
    }

    /**
     * Test that the order keywords are not retained as safety columns.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testExcludesTheOrderKeywordsFromRetainedColumns(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn([...self::USER_COLUMNS, 'random', 'relevance']);
        $introspector->method('getDeletedAtColumn')->willReturn(null);
        $introspector->method('resolveRelation')->willReturn(null);

        $applier = new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector);

        $columns = $applier->apply((new User)->newQuery(), $provider, UserResource::class, ['random' => 'asc', 'relevance' => 'asc'])->getQuery()->columns;

        self::assertNotNull($columns);
        self::assertNotContains('random', $columns);
        self::assertNotContains('relevance', $columns);
    }

    /**
     * Test that a relation field narrows the root, keeping the key the relation
     * matches on.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testARelationFieldNarrowsTheRootAndKeepsItsKey(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $columns = $this->applyThroughTheSchema((new User)->newQuery(), ['name', 'organization', 'posts'], ['organization', 'posts']);

        self::assertSame(['users.name', 'users.id', 'users.organization_id'], $columns);
    }

    /**
     * Test that a relation the model or repository already eager loads keeps
     * its key.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnEagerLoadTheQueryAlreadyCarriesKeepsItsKey(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $columns = $this->applyThroughTheSchema((new User)->newQuery()->with('organization'), ['name'], []);

        self::assertSame(['users.name', 'users.id', 'users.organization_id'], $columns);
    }

    /**
     * Test that a select the caller already chose is kept.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testASelectTheCallerChoseIsKept(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $query = (new User)->newQuery();

        $query->getQuery()->select('users.email');

        self::assertSame(['users.email'], $this->applyThroughTheSchema($query, ['name'], []));
    }

    /**
     * Test that an aggregate the caller already selected keeps its subselect
     * when the wildcard beside it is narrowed.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnAggregateTheCallerSelectedSurvivesTheNarrowing(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $query   = (new User)->newQuery()->withCount('posts');
        $columns = $this->applyThroughTheSchema($query, ['name', 'aggregates'], []);

        self::assertSame(['users.name', 'users.id'], array_slice($columns, 0, 2));
        self::assertCount(3, $columns);
        self::assertStringContainsString('as "posts_count"', $query->toSql());
    }

    /**
     * Test that an aggregate whose guard receives the resource leaves the
     * select unnarrowed.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAGuardedAggregateLeavesTheSelectUnnarrowed(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);
        Config::set(ResourceDiscovery::RESOURCES_KEY, [MeteredPostResource::class, TagResource::class, UserResource::class]);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('metered_posts');
        $provider->method('resolveFields')->willReturn(['id', 'title', 'aggregates']);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $introspector = $this->realIntrospector();
        $applier      = new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector);

        self::assertNull($applier->apply((new Post)->newQuery(), $provider, MeteredPostResource::class, [])->getQuery()->columns);
    }

    /**
     * Test that ':all' renders the aggregates payload, so a guarded aggregate
     * leaves the select unnarrowed.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAllFieldsTokenCarriesTheAggregatesPayload(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);
        Config::set(ResourceDiscovery::RESOURCES_KEY, [MeteredPostResource::class, TagResource::class, UserResource::class]);

        $this->parseRequest(new Request(['fields' => ['metered_posts' => ':all']]));

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('metered_posts');
        $provider->method('getAllFields')->willReturn(['id', 'title']);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $introspector = $this->realIntrospector();
        $applier      = new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector);

        self::assertNull($applier->apply((new Post)->newQuery(), $provider, MeteredPostResource::class, [])->getQuery()->columns);
    }

    /**
     * Test that a chaperoned relation leaves the select unnarrowed and logs the
     * relation.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAChaperonedRelationLeavesTheSelectUnnarrowedAndIsLogged(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(['name', 'posts']);
        $provider->method('eagerLoadMapFor')->willReturn(['posts']);

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn(self::USER_COLUMNS);
        $introspector->method('resolveRelation')->willReturn((new User)->posts()->chaperone('user'));
        $introspector->method('parentKeysFor')->willReturn(['id']);

        Log::shouldReceive('debug')
            ->once()
            ->with('Column narrowing fell back to a full select', ['model' => User::class, 'resource' => UserResource::class, 'relation' => 'posts']);

        $applier = new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector);

        self::assertNull($applier->apply((new User)->newQuery(), $provider, UserResource::class, [])->getQuery()->columns);
    }

    /**
     * Apply the applier to the query for the given fields of the user resource,
     * reading the real schema.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>  $query
     * @param  array<int, string>  $fields
     * @param  array<int|string, mixed>  $eagerLoads
     * @return array<int, mixed>
     */
    private function applyThroughTheSchema(Builder $query, array $fields, array $eagerLoads): array
    {
        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn($fields);
        $provider->method('eagerLoadMapFor')->willReturn($eagerLoads);

        $introspector = $this->realIntrospector();

        return (array) (new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector))->apply($query, $provider, UserResource::class, [])->getQuery()->columns;
    }

    /**
     * Resolve the introspector reading the migrated test schema.
     *
     * @return \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider
     */
    private function realIntrospector(): SchemaIntrospectionProvider
    {
        assert($this->app !== null);

        return $this->app->make(SchemaIntrospectionProvider::class);
    }

    /**
     * Build a column projection applier over the fixture user columns.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier
     */
    private function makeApplier(): ColumnProjectionApplier
    {
        $introspector = $this->makeIntrospector();

        return new ColumnProjectionApplier(new SafetySetDeriver($introspector), $introspector);
    }

    /**
     * Build an introspector stub over the fixture user columns.
     *
     * @return \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider
     */
    private function makeIntrospector(): SchemaIntrospectionProvider
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn(self::USER_COLUMNS);
        $introspector->method('getDeletedAtColumn')->willReturn(null);
        $introspector->method('resolveRelation')->willReturn(null);

        return $introspector;
    }

    /**
     * Resolve the API query parser and parse the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function parseRequest(Request $request): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse($request);
    }
}
