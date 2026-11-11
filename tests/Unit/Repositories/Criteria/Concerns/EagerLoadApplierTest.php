<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
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

        $provider->method('eagerLoadCountsFor')
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

        $provider->method('eagerLoadCountsFor')
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
            ->method('eagerLoadCountsFor');

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

        $provider->method('eagerLoadCountsFor')
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

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

        self::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply calls withCount() when the count map is not empty.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyAddsEagerLoadCountsWhenCountMapIsNotEmpty(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
            'counts' => ['users' => 'posts'],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->with(UserResource::class, ['posts'])
            ->willReturn(['posts']);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

        $columns = $result->getQuery()->columns ?? [];

        self::assertNotEmpty($columns);
    }

    /**
     * Test that apply does not call withCount() when the count map is empty.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplySkipsEagerLoadCountsWhenCountMapIsEmpty(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = self::createStub(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

        self::assertEmpty($result->getQuery()->columns ?? []);
    }

    /**
     * Test that apply calls withSum() for each entry of the sum map.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyAddsWithSumWhenSumEntriesExist(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
            'sums'   => ['users' => ['posts' => 'id']],
        ]));

        $provider = self::createStub(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $provider->method('eagerLoadSumsFor')
            ->willReturn([['relation' => 'posts as posts_id', 'column' => 'id']]);

        $provider->method('eagerLoadAveragesFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

        self::assertNotNull($result->getQuery()->columns);
    }

    /**
     * Test that a constrained sum or average is loaded through its constraint.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyLoadsConstrainedSumsAndAverages(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $constraint = static function (Builder $query): void {
            $query->where('published', true);
        };

        $provider = self::createStub(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $provider->method('eagerLoadSumsFor')
            ->willReturn([['relation' => ['posts as posts_sum_id' => $constraint], 'column' => 'id']]);

        $provider->method('eagerLoadAveragesFor')
            ->willReturn([['relation' => ['posts as posts_avg_id' => $constraint], 'column' => 'id']]);

        $query = (new User)->newQuery();
        $sql   = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate())->toSql();

        self::assertStringContainsString('sum("posts"."id")', $sql);
        self::assertStringContainsString('avg("posts"."id")', $sql);
        self::assertStringContainsString('"posts_sum_id"', $sql);
        self::assertStringContainsString('"posts_avg_id"', $sql);
        self::assertSame(2, substr_count($sql, '"published" = ?'));
    }

    /**
     * Test that apply skips withSum() when the sum entry list is empty.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplySkipsWithSumWhenSumEntriesEmpty(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = self::createStub(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $provider->method('eagerLoadSumsFor')
            ->willReturn([]);

        $provider->method('eagerLoadAveragesFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

        self::assertEmpty($result->getQuery()->columns ?? []);
    }

    /**
     * Test that apply passes the requested sums to eagerLoadSumsFor.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyPassesActualRequestedSumsToEagerLoadSumsFor(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
            'sums'   => ['users' => ['posts' => 'id']],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $provider->expects(self::once())
            ->method('eagerLoadSumsFor')
            ->with(UserResource::class, ['posts' => ['id']])
            ->willReturn([]);

        $provider->method('eagerLoadAveragesFor')
            ->willReturn([]);

        $query = (new User)->newQuery();
        $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());
    }

    /**
     * Test that apply passes the requested averages to eagerLoadAveragesFor.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyPassesActualRequestedAveragesToEagerLoadAveragesFor(): void
    {
        $this->parseRequest(new Request([
            'fields'   => ['users' => self::STUB_USER_FIELDS],
            'averages' => ['users' => ['posts' => 'id']],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $provider->method('eagerLoadSumsFor')
            ->willReturn([]);

        $provider->expects(self::once())
            ->method('eagerLoadAveragesFor')
            ->with(UserResource::class, ['posts' => ['id']])
            ->willReturn([]);

        $query = (new User)->newQuery();
        $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());
    }

    /**
     * Test that apply calls withAvg() for each entry of the average map.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyAddsWithAvgWhenAvgEntriesExist(): void
    {
        $this->parseRequest(new Request([
            'fields'   => ['users' => self::STUB_USER_FIELDS],
            'averages' => ['users' => ['posts' => 'id']],
        ]));

        $provider = self::createStub(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $provider->method('eagerLoadSumsFor')
            ->willReturn([]);

        $provider->method('eagerLoadAveragesFor')
            ->willReturn([['relation' => 'posts as posts_id', 'column' => 'id']]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users', $this->gate());

        self::assertNotNull($result->getQuery()->columns, 'withAvg must be applied when avg entries are returned');
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
