<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the base API resource collection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1075")
 *
 * @internal
 */
#[CoversClass(ApiResourceCollection::class)]
final class ApiResourceCollectionTest extends TestCase
{
    /** @var string Base path used to build paginator links in tests */
    private const string PAGINATION_PATH = 'http://localhost/api/users';

    /**
     * Test that toArray resolves each item via the resource class.
     *
     * @return void
     */
    public function testToArrayResolvesEachItemViaResourceClass(): void
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $collection = new ApiResourceCollection(collect([$user1, $user2]), UserResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->toArray($request);

        static::assertCount(2, $result);
        static::assertSame('users', $result[0]['_type']);
        static::assertSame('users', $result[1]['_type']);
    }

    /**
     * Test that toArray applies withFields to each item.
     *
     * @return void
     */
    public function testToArrayAppliesWithFieldsToEachItem(): void
    {
        $user1 = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $user2 = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);

        $collection = new ApiResourceCollection(collect([$user1, $user2]), UserResource::class);
        $collection->withFields(['name']);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->toArray($request);

        foreach ($result as $item) {
            static::assertArrayHasKey('name', $item);
            static::assertArrayNotHasKey('email', $item);
            static::assertArrayNotHasKey('status', $item);
        }
    }

    /**
     * Test that toArray applies withoutFields to each item.
     *
     * @return void
     */
    public function testToArrayAppliesWithoutFieldsToEachItem(): void
    {
        $user1 = User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
        $user2 = User::create(['name' => 'Dana', 'email' => 'dana@example.com']);

        $collection = new ApiResourceCollection(collect([$user1, $user2]), UserResource::class);
        $collection->withoutFields(['email']);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->toArray($request);

        foreach ($result as $item) {
            static::assertArrayNotHasKey('email', $item);
            static::assertArrayHasKey('name', $item);
        }
    }

    /**
     * Test that withFields is fluent.
     *
     * @return void
     */
    public function testWithFieldsIsFluent(): void
    {
        $collection = new ApiResourceCollection(collect([]), UserResource::class);

        $result = $collection->withFields(['name']);

        static::assertSame($collection, $result);
    }

    /**
     * Test that withoutFields is fluent.
     *
     * @return void
     */
    public function testWithoutFieldsIsFluent(): void
    {
        $collection = new ApiResourceCollection(collect([]), UserResource::class);

        $result = $collection->withoutFields(['name']);

        static::assertSame($collection, $result);
    }

    /**
     * Test that withResponse sets Total-Count header for LengthAwarePaginator.
     *
     * @return void
     */
    public function testWithResponseSetsTotalCountHeaderForLengthAwarePaginator(): void
    {
        $items     = [User::create(['name' => 'Paged', 'email' => 'paged@example.com'])];
        $paginator = new LengthAwarePaginator($items, 50, 15, 1);

        $collection = new ApiResourceCollection($paginator, UserResource::class);

        $request  = Request::create('/', HttpMethod::GET->getVerb());
        $response = new JsonResponse([]);

        $collection->withResponse($request, $response);

        static::assertSame('50', $response->headers->get('Total-Count'));
    }

    /**
     * Test that withResponse does not set header for non-paginator resources.
     *
     * @return void
     */
    public function testWithResponseDoesNotSetHeaderForNonPaginator(): void
    {
        $collection = new ApiResourceCollection(collect([]), UserResource::class);

        $request  = Request::create('/', HttpMethod::GET->getVerb());
        $response = new JsonResponse([]);

        $collection->withResponse($request, $response);

        static::assertNull($response->headers->get('Total-Count'));
    }

    /**
     * Test that paginationInformation for LengthAwarePaginator returns meta
     * and links.
     *
     * @return void
     */
    public function testPaginationInformationForLengthAwarePaginator(): void
    {
        $items     = [User::create(['name' => 'PagedMeta', 'email' => 'pagedmeta@example.com'])];
        $paginator = new LengthAwarePaginator($items, 50, 15, 2, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection = new ApiResourceCollection($paginator, UserResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->paginationInformation($request, [], []);

        static::assertArrayHasKey('meta', $result);
        static::assertArrayHasKey('links', $result);
        static::assertSame(50, $result['meta']['total']);
        static::assertSame(1, $result['meta']['count']);
        static::assertArrayHasKey('continue', $result['meta']);
        static::assertArrayHasKey('self', $result['links']);
        static::assertArrayHasKey('first', $result['links']);
        static::assertArrayHasKey('prev', $result['links']);
        static::assertArrayHasKey('next', $result['links']);
        static::assertArrayHasKey('last', $result['links']);
    }

    /**
     * Test that paginationInformation for CursorPaginator returns cursor
     * meta and links.
     *
     * @return void
     */
    public function testPaginationInformationForCursorPaginator(): void
    {
        $items     = [User::create(['name' => 'CursorUser', 'email' => 'cursor@example.com'])];
        $paginator = new CursorPaginator($items, 15, null, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection = new ApiResourceCollection($paginator, UserResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->paginationInformation($request, [], []);

        static::assertArrayHasKey('meta', $result);
        static::assertArrayHasKey('links', $result);
        static::assertArrayHasKey('continue', $result['meta']);
        static::assertArrayHasKey('self', $result['links']);
        static::assertArrayHasKey('prev', $result['links']);
        static::assertArrayHasKey('next', $result['links']);
    }

    /**
     * Test that paginationInformation for non-paginator returns empty array.
     *
     * @return void
     */
    public function testPaginationInformationForNonPaginatorReturnsEmptyArray(): void
    {
        $collection = new ApiResourceCollection(collect([]), UserResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->paginationInformation($request, [], []);

        static::assertSame([], $result);
    }

    /**
     * Test that paginationInformation reports continue correctly.
     *
     * @return void
     */
    public function testPaginationInformationReportsContinueCorrectly(): void
    {
        $items = [User::create(['name' => 'ContinueTest', 'email' => 'continue@example.com'])];

        $has_more = new LengthAwarePaginator($items, 50, 15, 1, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection_more = new ApiResourceCollection($has_more, UserResource::class);
        $result_more     = $collection_more->paginationInformation(Request::create('/', HttpMethod::GET->getVerb()), [], []);

        static::assertTrue($result_more['meta']['continue']);

        $no_more = new LengthAwarePaginator($items, 1, 15, 1, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection_last = new ApiResourceCollection($no_more, UserResource::class);
        $result_last     = $collection_last->paginationInformation(Request::create('/', HttpMethod::GET->getVerb()), [], []);

        static::assertFalse($result_last['meta']['continue']);
    }

    /**
     * Test that toArray resolves a raw model item (not a pre-wrapped resource)
     * using the collection's resource class.
     *
     * ApiResourceCollection::collectResource() wraps items via mapInto(), so
     * $this->resource always contains ApiResource instances under normal use.
     * To exercise the false branch on line 54 (the raw-item path), we bypass
     * the constructor wrapping by setting $this->resource via reflection to a
     * plain model object collection.
     *
     * @return void
     */
    public function testToArrayResolvesRawModelItemViaResourceClass(): void
    {
        $user = User::create(['name' => 'Raw', 'email' => 'raw@example.com']);

        // Construct with an empty collection to avoid wrapping overhead,
        // then inject the raw model item directly.
        $collection = new ApiResourceCollection(collect([]), UserResource::class);

        // $this->collection is set to $this->resource->values() in the
        // ResourceCollection constructor; inject the raw User directly so
        // the false-branch of `instanceof ApiResource` in toArray() fires.
        $reflection = new \ReflectionProperty($collection, 'collection');
        $reflection->setValue($collection, collect([$user])); // NOSONAR

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->toArray($request);

        static::assertCount(1, $result);
        static::assertSame('users', $result[0]['_type']);
    }

    /**
     * Test that toArray resolves pre-wrapped items using their own field
     * configuration rather than re-wrapping them.
     *
     * @return void
     */
    public function testToArrayPreservesItemLevelFieldSelection(): void
    {
        $user = User::create(['name' => 'ItemFields', 'email' => 'itemfields@example.com']);

        $item = new UserResource($user);
        $item->withFields(['name']);

        $collection = new ApiResourceCollection(collect([$item]), UserResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->toArray($request);

        static::assertCount(1, $result);
        static::assertArrayHasKey('name', $result[0]);
        static::assertArrayNotHasKey('email', $result[0]);
    }

    /**
     * Test that toArray does not eager load missing relations when wrapping
     * raw model items.
     *
     * @return void
     */
    public function testToArrayDoesNotEagerLoadRelationsForRawItems(): void
    {
        $organization = Organization::create([
            'name' => 'Raw Corp',
            'slug' => 'raw-corp',
        ]);

        $user = User::create([
            'name'            => 'RawRelation',
            'email'           => 'rawrelation@example.com',
            'organization_id' => $organization->id,
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,organization'],
        ]);

        $parser->parse($request);

        $collection = new ApiResourceCollection(collect([]), UserResource::class);

        $reflection = new \ReflectionProperty($collection, 'collection');
        $reflection->setValue($collection, collect([$user])); // NOSONAR

        $result = $collection->toArray($request);

        static::assertFalse($user->relationLoaded('organization'));
        static::assertArrayNotHasKey('organization', $result[0]);
    }

    /**
     * Test that pagination links point at the correct page numbers.
     *
     * @return void
     */
    public function testPaginationLinksPointAtCorrectPages(): void
    {
        $items     = [User::create(['name' => 'LinkPages', 'email' => 'linkpages@example.com'])];
        $paginator = new LengthAwarePaginator($items, 50, 15, 2, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection = new ApiResourceCollection($paginator, UserResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->paginationInformation($request, [], []);

        static::assertSame(self::PAGINATION_PATH . '?page=1', $result['links']['first']);
        static::assertSame(self::PAGINATION_PATH . '?page=2', $result['links']['self']);
        static::assertSame(self::PAGINATION_PATH . '?page=4', $result['links']['last']);
    }

    /**
     * Test that toArray resolves items when they are already ApiResource
     * instances.
     *
     * @return void
     */
    public function testToArrayResolvesApiResourceInstances(): void
    {
        $user     = User::create(['name' => 'Wrapped', 'email' => 'wrapped@example.com']);
        $resource = new UserResource($user);

        $collection = new ApiResourceCollection(collect([$resource]), UserResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->toArray($request);

        static::assertCount(1, $result);
        static::assertSame('users', $result[0]['_type']);
    }
}
