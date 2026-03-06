<?php

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
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
class EagerLoadApplierTest extends TestCase
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
    protected function setUp(): void
    {
        parent::setUp();

        $this->applier = new EagerLoadApplier;
    }

    /**
     * Test that apply with a null resource class returns the query
     * unmodified.
     *
     * @return void
     */
    public function testApplyWithNullResourceClassReturnsUnmodifiedQuery(): void
    {
        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(static::never())->method('resolveFields');
        $provider->expects(static::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, null, 'users');

        static::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply with a non-ApiResource class returns the query
     * unmodified.
     *
     * @return void
     */
    public function testApplyWithNonApiResourceClassReturnsUnmodifiedQuery(): void
    {
        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(static::never())->method('resolveFields');
        $provider->expects(static::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, \stdClass::class, 'users');

        static::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply uses getAllFields when the ':all' token is
     * present in the fields array.
     *
     * @return void
     */
    public function testApplyWithAllTokenUsesGetAllFields(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => ':all'],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->expects(static::once())
            ->method('getAllFields')
            ->with(UserResource::class)
            ->willReturn(['id', 'name', 'organization']);

        $provider->expects(static::never())
            ->method('resolveFields');

        $provider->method('eagerLoadMapFor')
            ->willReturn(['organization' => fn () => null]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $query = (new User)->newQuery();
        $this->applier->apply($query, $provider, UserResource::class, 'users');
    }

    /**
     * Test that apply uses resolveFields when the ':all' token is not
     * present in the fields array.
     *
     * @return void
     */
    public function testApplyWithSpecificFieldsUsesResolveFields(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->expects(static::once())
            ->method('resolveFields')
            ->with(UserResource::class)
            ->willReturn(['id', 'name']);

        $provider->expects(static::never())
            ->method('getAllFields');

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $query = (new User)->newQuery();
        $this->applier->apply($query, $provider, UserResource::class, 'users');
    }

    /**
     * Test that apply returns early without calling eagerLoadMapFor
     * when the resolved fields array is empty.
     *
     * @return void
     */
    public function testApplyWithEmptyFieldsReturnsEarlyWithoutEagerLoading(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => 'id'],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn([]);

        $provider->expects(static::never())
            ->method('eagerLoadMapFor');

        $provider->expects(static::never())
            ->method('eagerLoadCountsFor');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users');

        static::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply calls with() on the query when the eager load
     * map is not empty.
     *
     * @return void
     */
    public function testApplyAddsEagerLoadsWhenMapIsNotEmpty(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => 'id,name,organization'],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name', 'organization']);

        $provider->method('eagerLoadMapFor')
            ->willReturn(['organization' => fn () => null]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users');

        static::assertArrayHasKey('organization', $result->getEagerLoads());
    }

    /**
     * Test that apply does not call with() on the query when the
     * eager load map is empty.
     *
     * @return void
     */
    public function testApplySkipsEagerLoadsWhenMapIsEmpty(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users');

        static::assertEmpty($result->getEagerLoads());
    }

    /**
     * Test that apply calls withCount() on the query when the eager
     * load count map is not empty.
     *
     * @return void
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
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users');

        $columns = $result->getQuery()->columns ?? [];

        static::assertNotEmpty($columns);
    }

    /**
     * Test that apply does not call withCount() on the query when the
     * eager load count map is empty.
     *
     * @return void
     */
    public function testApplySkipsEagerLoadCountsWhenCountMapIsEmpty(): void
    {
        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, $provider, UserResource::class, 'users');

        static::assertEmpty($result->getQuery()->columns ?? []);
    }

    /**
     * Resolve the API query parser and parse the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function parseRequest(Request $request): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse($request);
    }
}
