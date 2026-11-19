<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\PageOrder;
use SineMacula\ApiToolkit\Repositories\Pagination\RawCursorPaginator;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\WalksPaginationLinks;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Event;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Models\Release;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CountryRepository;
use Tests\Fixtures\Repositories\EventRepository;
use Tests\Fixtures\Repositories\LogRepository;
use Tests\Fixtures\Repositories\ReleaseRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\CountryResource;
use Tests\Fixtures\Resources\EventResource;
use Tests\Fixtures\Resources\LogResource;
use Tests\Fixtures\Resources\ReleaseResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests walking cursor pages ordered by cast and non-integer keys.
 *
 * Each walk follows every next link to the end and every prev link back, and
 * must visit each row once, in order, with the same page boundaries both ways.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(PageOrder::class)]
#[CoversClass(RawCursorPaginator::class)]
final class CastKeyCursorPaginationTest extends TestCase
{
    use RegistersApiExceptionHandler, WalksPaginationLinks;

    /**
     * Set up a cursor-capable route per keyed fixture.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            CountryResource::class,
            EventResource::class,
            LogResource::class,
            ReleaseResource::class,
            UserResource::class,
        ]);

        $this->registerApiExceptionHandler();

        $routes = [
            '/countries' => [CountryRepository::class, CountryResource::class],
            '/events'    => [EventRepository::class, EventResource::class],
            '/logs'      => [LogRepository::class, LogResource::class],
            '/releases'  => [ReleaseRepository::class, ReleaseResource::class],
            '/users'     => [UserRepository::class, UserResource::class],
        ];

        foreach ($routes as $uri => [$repository, $resource]) {
            Route::middleware(ParseApiQuery::class)->get($uri, function () use ($repository, $resource): ApiResourceCollection {

                $instance = $this->app?->make($repository);

                assert($instance instanceof ApiRepository);

                return new ApiResourceCollection($instance->usingResource($resource)->withApiCriteria()->paginate(), $resource);
            });
        }
    }

    /**
     * Test that a microsecond timestamp key pages rows tied within and across
     * one second once each way.
     *
     * @return void
     */
    public function testMicrosecondTimestampKeyPagesEachRowOnceBothWays(): void
    {
        $stamps = [
            'a' => '00.000000',
            'b' => '00.250000',
            'c' => '00.250000',
            'd' => '00.999999',
            'e' => '01.000000',
            'f' => '01.000001',
            'g' => '00.500000',
        ];

        foreach ($stamps as $name => $stamp) {
            Event::create(['name' => $name, 'occurred_at' => '2026-01-01 10:00:' . $stamp]);
        }

        $first = $this->getJson('/events?pagination=cursor&order=occurred_at&limit=2');

        $boundary = $this->boundaryOf($first, 'events.occurred_at');

        self::assertIsString($boundary);
        self::assertStringStartsWith('2026-01-01 10:00:00.25', $boundary);
        self::assertSame([['a', 'b'], ['c', 'g'], ['d', 'e'], ['f']], $this->walk($first, 'name'));
    }

    /**
     * Test that a pure enum key pages its tied rows once each way, its cursor
     * carrying the stored case name.
     *
     * @return void
     */
    public function testPureEnumKeyPagesEachRowOnceBothWays(): void
    {
        $platforms = [
            'r1' => Platform::WEB,
            'r2' => Platform::DESKTOP,
            'r3' => Platform::WEB,
            'r4' => Platform::MOBILE_APP,
            'r5' => Platform::DESKTOP,
            'r6' => Platform::WEB,
        ];

        foreach ($platforms as $name => $platform) {
            Release::create(['name' => $name, 'platform' => $platform]);
        }

        $first = $this->getJson('/releases?pagination=cursor&order=platform&limit=2');

        self::assertSame('DESKTOP', $this->boundaryOf($first, 'releases.platform'));
        self::assertSame([['r2', 'r5'], ['r4', 'r1'], ['r3', 'r6']], $this->walk($first, 'name'));
    }

    /**
     * Test that a mixed-direction order over a backed enum and a tied name
     * pages each row once each way.
     *
     * @return void
     */
    public function testMixedDirectionOrderPagesEachRowOnceBothWays(): void
    {
        $users = [
            'u1' => ['Same', 'active'],
            'u2' => ['Same', 'inactive'],
            'u3' => ['Abe', 'inactive'],
            'u4' => ['Same', 'banned'],
            'u5' => ['Abe', 'active'],
            'u6' => ['Same', 'inactive'],
            'u7' => ['Same', 'active'],
        ];

        foreach ($users as $local => [$name, $status]) {
            User::create(['name' => $name, 'email' => $local . '@example.com', 'status' => $status]);
        }

        $first = $this->getJson('/users?pagination=cursor&order=status:desc,name:asc&limit=2');

        self::assertSame('inactive', $this->boundaryOf($first, 'users.status'));
        self::assertSame([
            ['u3@example.com', 'u2@example.com'],
            ['u6@example.com', 'u4@example.com'],
            ['u5@example.com', 'u1@example.com'],
            ['u7@example.com'],
        ], $this->walk($first, 'email'));
    }

    /**
     * Test that rows tied on a sort are paged once each way by their UUID key.
     *
     * @return void
     */
    public function testUuidKeyPagesTiedRowsOnceBothWays(): void
    {
        $id = static fn (int $number): string => sprintf('00000000-0000-0000-0000-%012d', $number);

        foreach ([5 => 'info', 1 => 'error', 4 => 'info', 2 => 'info', 3 => 'error'] as $number => $level) {
            Log::query()->forceCreate(['id' => $id($number), 'level' => $level, 'message' => $level]);
        }

        $pages = $this->walk($this->getJson('/logs?pagination=cursor&order=level&limit=2'), 'id');

        self::assertSame([[$id(1), $id(3)], [$id(2), $id(4)], [$id(5)]], $pages);
    }

    /**
     * Test that a string key orders and pages each row once each way.
     *
     * @return void
     */
    public function testStringKeyPagesEachRowOnceBothWays(): void
    {
        foreach (['FR', 'AU', 'DE', 'CA', 'BR'] as $code) {
            Country::create(['code' => $code, 'name' => $code]);
        }

        $first = $this->getJson('/countries?pagination=cursor&limit=2');

        self::assertSame('BR', $this->boundaryOf($first, 'countries.code'));
        self::assertSame([['AU', 'BR'], ['CA', 'DE'], ['FR']], $this->walk($first, 'code'));
    }

    /**
     * Decode the boundary the page's next cursor carries for the column.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $page
     * @param  string  $column
     * @return mixed
     */
    private function boundaryOf(TestResponse $page, string $column): mixed
    {
        $next = $page->json('links.next');

        self::assertIsString($next);

        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);

        $cursor = Cursor::fromEncoded(is_string($query['cursor'] ?? null) ? $query['cursor'] : '');

        self::assertInstanceOf(Cursor::class, $cursor);

        return $cursor->parameter($column);
    }
}
