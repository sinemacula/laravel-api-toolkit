<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use Tests\Fixtures\Models\User;
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
        $provider->expects(self::never())->method('resolveFields');
        $provider->expects(self::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, null, 'users', $this->gate());

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
        $provider->expects(self::never())->method('resolveFields');
        $provider->expects(self::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, \stdClass::class, 'users', $this->gate());

        self::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply uses getAllFields when the ':all' token is present.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithAllTokenUsesGetAllFields(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => ':all'],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->expects(self::once())
            ->method('getAllFields')
            ->with(UserResource::class)
            ->willReturn(['id', 'name', 'organization']);

        $provider->expects(self::never())
            ->method('resolveFields');

        $provider->method('eagerLoadMapFor')
            ->willReturn(['organization' => fn () => null]);

        $provider->method('eagerLoadAggregatesFor')
            ->willReturn([]);

        $query = (new User)->newQuery();
        $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());
    }

    /**
     * Test that apply uses resolveFields when the ':all' token is absent.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithSpecificFieldsUsesResolveFields(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->expects(self::once())
            ->method('resolveFields')
            ->with(UserResource::class)
            ->willReturn(['id', 'name']);

        $provider->expects(self::never())
            ->method('getAllFields');

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadAggregatesFor')
            ->willReturn([]);

        $query = (new User)->newQuery();
        $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());
    }

    /**
     * Test that apply returns early when the resolved fields are empty.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithEmptyFieldsReturnsEarlyWithoutEagerLoading(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => 'id'],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn([]);

        $provider->expects(self::never())
            ->method('eagerLoadMapFor');

        $provider->expects(self::never())
            ->method('eagerLoadAggregatesFor');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

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

        $provider->method('resolveFields')
            ->willReturn(['id', 'name', 'organization']);

        $provider->method('eagerLoadMapFor')
            ->willReturn(['organization' => fn () => null]);

        $provider->method('eagerLoadAggregatesFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

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

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadAggregatesFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

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
            'fields'     => ['users' => self::STUB_USER_FIELDS],
            'aggregates' => ['users' => 'posts_count,posts_sum_id'],
        ]));

        $constraint = static function (Builder $query): void {
            $query->where('published', true);
        };

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

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
        $sql   = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate())->toSql();

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
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->expects(self::once())
            ->method('eagerLoadAggregatesFor')
            ->with(UserResource::class, [])
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

        self::assertEmpty($result->getQuery()->columns ?? []);
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
}
