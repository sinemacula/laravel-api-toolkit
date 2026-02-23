<?php

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
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
class ApiResourceCollectionTest extends TestCase
{
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

        $request = Request::create('/', 'GET');
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

        $request = Request::create('/', 'GET');
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

        $request = Request::create('/', 'GET');
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

        $request  = Request::create('/', 'GET');
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

        $request  = Request::create('/', 'GET');
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

        $request = Request::create('/', 'GET');
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

        $request = Request::create('/', 'GET');
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

        $request = Request::create('/', 'GET');
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
        $result_more     = $collection_more->paginationInformation(Request::create('/', 'GET'), [], []);

        static::assertTrue($result_more['meta']['continue']);

        $no_more = new LengthAwarePaginator($items, 1, 15, 1, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection_last = new ApiResourceCollection($no_more, UserResource::class);
        $result_last     = $collection_last->paginationInformation(Request::create('/', 'GET'), [], []);

        static::assertFalse($result_last['meta']['continue']);
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

        $request = Request::create('/', 'GET');
        $result  = $collection->toArray($request);

        static::assertCount(1, $result);
        static::assertSame('users', $result[0]['_type']);
    }
}
