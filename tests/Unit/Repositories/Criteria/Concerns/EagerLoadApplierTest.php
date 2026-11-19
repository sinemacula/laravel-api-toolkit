<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Http\Resources\ResourceMetadataService;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationColumnNarrower;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the EagerLoadApplier concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EagerLoadApplier::class)]
final class EagerLoadApplierTest extends TestCase
{
    /** @var string */
    private const string STUB_USER_FIELDS = 'id,name';

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier */
    private EagerLoadApplier $applier;

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

        $this->applier = new EagerLoadApplier;
    }

    /**
     * Test that apply with a null resource class returns the query unmodified.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNullResourceClassReturnsUnmodifiedQuery(): void
    {
        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(self::never())->method('eagerLoadMapFor');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, null, 'users', $this->gate(), $this->narrower());

        self::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply leaves the query unmodified when no resource type is
     * known.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNullResourceTypeReturnsUnmodifiedQuery(): void
    {
        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(self::never())->method('eagerLoadMapFor');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, null, $this->gate(), $this->narrower());

        self::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply leaves the query unmodified for a non-ApiResource class.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNonApiResourceClassReturnsUnmodifiedQuery(): void
    {
        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(self::never())->method('eagerLoadMapFor');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, \stdClass::class, 'users', $this->gate(), $this->narrower());

        self::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that the ':all' token plans every field and the aggregates.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyPlansEveryFieldAndTheAggregatesUnderTheAllToken(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => ':all'],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->expects(self::once())
            ->method('eagerLoadMapFor')
            ->with(UserResource::class, [...UserResource::getAllFields(), 'aggregates'])
            ->willReturn([]);

        $provider->expects(self::once())
            ->method('eagerLoadAggregatesFor')
            ->willReturn([]);

        $query = (new User)->newQuery();
        $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate(), $this->narrower());
    }

    /**
     * Test that apply plans the requested fields followed by the fixed fields.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyPlansTheRequestedAndFixedFields(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('getFixedFields')
            ->with(UserResource::class)
            ->willReturn(['organization']);

        $provider->expects(self::once())
            ->method('eagerLoadMapFor')
            ->with(UserResource::class, ['id', 'name', 'organization'])
            ->willReturn([]);

        $query = (new User)->newQuery();
        $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate(), $this->narrower());
    }

    /**
     * Test that apply returns early when the resource selects no field.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithEmptyFieldsReturnsEarlyWithoutEagerLoading(): void
    {
        $this->parseRequest(new Request);

        $resource = new class (null) extends ApiResource {
            /** @var string The resource type identifier. */
            public const string RESOURCE_TYPE = 'fieldless_users';

            /**
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'));
            }
        };

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->expects(self::never())
            ->method('eagerLoadMapFor');

        $provider->expects(self::never())
            ->method('eagerLoadAggregatesFor');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, $resource::class, 'fieldless_users', $this->gate(), $this->narrower());

        self::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply calls with() when the eager load map is not empty.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyAddsEagerLoadsWhenMapIsNotEmpty(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => 'id,name,organization'],
        ]));

        $provider = self::createStub(ResourceMetadataProvider::class);

        $provider->method('eagerLoadMapFor')
            ->willReturn(['organization' => fn () => null]);

        $provider->method('eagerLoadAggregatesFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate(), $this->narrower());

        self::assertArrayHasKey('organization', $result->getEagerLoads());
    }

    /**
     * Test that apply does not call with() when the eager load map is empty.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplySkipsEagerLoadsWhenMapIsEmpty(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = self::createStub(ResourceMetadataProvider::class);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadAggregatesFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate(), $this->narrower());

        self::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply passes the requested aggregate keys to the provider and
     * selects every entry through withAggregate(), constrained or not.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplySelectsEveryAggregateEntry(): void
    {
        $this->parseRequest(new Request([
            'fields'     => ['users' => 'id,name,aggregates'],
            'aggregates' => ['users' => 'posts_count,posts_sum_id'],
        ]));

        $constraint = static function (Builder $query): void {
            $query->where('published', true);
        };

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->expects(self::once())
            ->method('eagerLoadAggregatesFor')
            ->with(UserResource::class, ['posts_count', 'posts_sum_id'])
            ->willReturn([
                ['relation' => 'posts as aggregate_posts_count', 'column' => '*', 'function' => 'count'],
                ['relation' => ['posts as aggregate_published_posts' => $constraint], 'column' => '*', 'function' => 'exists'],
                ['relation' => 'posts as aggregate_posts_max_created_at', 'column' => 'created_at', 'function' => 'max'],
            ]);

        $query = (new User)->newQuery();
        $sql   = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate(), $this->narrower())->toSql();

        self::assertStringContainsString('count(*)', $sql);
        self::assertStringContainsString('"aggregate_posts_count"', $sql);
        self::assertStringContainsString('exists(select', $sql);
        self::assertStringContainsString('"aggregate_published_posts"', $sql);
        self::assertStringContainsString('max("posts"."created_at")', $sql);
        self::assertStringContainsString('"aggregate_posts_max_created_at"', $sql);
        self::assertSame(1, substr_count($sql, '"published" = ?'));
    }

    /**
     * Test that apply passes an empty request to the provider and leaves the
     * select untouched when no aggregate is planned.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplySelectsNothingWithoutAggregateEntries(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => 'id,name,aggregates'],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->expects(self::once())
            ->method('eagerLoadAggregatesFor')
            ->with(UserResource::class, [])
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate(), $this->narrower());

        self::assertEmpty($result->getQuery()->columns ?? []);
    }

    /**
     * Test that apply plans no aggregate the selection leaves out, defaults
     * included.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyLoadsNoAggregateTheSelectionLeavesOut(): void
    {
        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->expects(self::never())
            ->method('eagerLoadAggregatesFor');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate(), $this->narrower());

        self::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that only the entries the eager loader accepts are loaded.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyLoadsOnlyTheEntriesTheEagerLoaderAccepts(): void
    {
        $this->parseRequest(new Request(['fields' => ['users' => 'id,name']]));

        $provider = self::createStub(ResourceMetadataProvider::class);
        $provider->method('eagerLoadMapFor')->willReturn(['organization', 'profile' => 42, 'posts' => null]);

        $query = $this->applier->apply((new User)->newQuery(), $provider, UserResource::class, 'users', $this->gate(), $this->narrower());

        self::assertSame(['organization'], array_keys($query->getEagerLoads()));
    }

    /**
     * Test that an embedded relation selects only the columns its child
     * resource renders, with its keys.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyNarrowsAnEmbeddedRelationToItsRenderedColumns(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);
        Config::set(ResourceDiscovery::RESOURCES_KEY, [PostResource::class, TagResource::class, UserResource::class]);

        $this->parseRequest(new Request(['fields' => ['users' => 'name,posts', 'posts' => 'title']]));

        $query = $this->applier->apply((new User)->newQuery(), new ResourceMetadataService, UserResource::class, 'users', $this->gate(), $this->narrower());

        $constraint = $query->getEagerLoads()['posts'];
        $relation   = Relation::noConstraints(static fn (): Relation => (new User)->posts());

        $constraint($relation);

        self::assertSame(['posts.title', 'posts.id', 'posts.user_id'], $relation->getQuery()->getQuery()->columns);
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

    /**
     * Build a relation trashed gate for the applier under test.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate
     */
    private function gate(): RelationTrashedGate
    {
        assert($this->app !== null);

        return new RelationTrashedGate(
            $this->app->make(SchemaIntrospectionProvider::class),
            new Request,
            [],
        );
    }

    /**
     * Build a relation column narrower for the applier under test.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationColumnNarrower
     */
    private function narrower(): RelationColumnNarrower
    {
        assert($this->app !== null);

        $introspector = $this->app->make(SchemaIntrospectionProvider::class);

        return new RelationColumnNarrower(new SafetySetDeriver($introspector), $introspector);
    }
}
