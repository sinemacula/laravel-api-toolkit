<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ProvidesApiEnvelope;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for cursor-paginated collections through the kernel.
 *
 * Requesting cursor mode via the query string selects the cursor paginator, so
 * the response carries the cursor envelope: a data block, a boolean
 * `meta.continue` flag, and `links` exposing the next/prev cursors. Following
 * the emitted next cursor returns the subsequent page of rows.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversTrait(ProvidesApiEnvelope::class)]
final class CursorPaginationTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a cursor-capable users route and ordered rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/api/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        // Created in ascending id order; the cursor paginator orders by the
        // primary key by default, so page order follows creation order.
        User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        User::create(['name' => 'Ben', 'email' => 'ben@example.com']);
        User::create(['name' => 'Cara', 'email' => 'cara@example.com']);
        User::create(['name' => 'Dan', 'email' => 'dan@example.com']);
        User::create(['name' => 'Evan', 'email' => 'evan@example.com']);
    }

    /**
     * Test that requesting cursor mode returns the cursor envelope on the first
     * page: data, a boolean continue flag, a next cursor, and a null prev.
     *
     * @return void
     */
    public function testCursorModeReturnsTheCursorEnvelope(): void
    {
        $response = $this->getJson('/api/users?pagination=cursor&limit=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Anna');
        $response->assertJsonPath('data.1.name', 'Ben');

        self::assertIsBool($response->json('meta.continue'));
        self::assertTrue($response->json('meta.continue'));

        self::assertNull($response->json('links.prev'));
        self::assertIsString($response->json('links.next'));
    }

    /**
     * Test that following the emitted next cursor returns the following page of
     * rows while the envelope shape is preserved.
     *
     * @return void
     */
    public function testFollowingTheNextCursorReturnsTheFollowingPage(): void
    {
        $first = $this->getJson('/api/users?pagination=cursor&limit=2');

        $first->assertOk();

        $next = $first->json('links.next');

        self::assertIsString($next);

        $parts = parse_url($next);

        self::assertIsArray($parts);
        self::assertArrayHasKey('query', $parts);

        $second = $this->getJson('/api/users?' . $parts['query']);

        $second->assertOk();
        $second->assertJsonCount(2, 'data');
        $second->assertJsonPath('data.0.name', 'Cara');
        $second->assertJsonPath('data.1.name', 'Dan');

        self::assertTrue($second->json('meta.continue'));

        // The prev cursor is populated once past the first page.
        self::assertIsString($second->json('links.prev'));
    }

    /**
     * Test that following the next cursors to the terminal page returns the
     * single trailing row with no further cursor.
     *
     * @return void
     */
    public function testLastCursorPageHasNoNextCursor(): void
    {
        $response = $this->getJson('/api/users?pagination=cursor&limit=2');

        $response->assertOk();

        // Walk the next cursors until the terminal page is reached.
        while (is_string($next = $response->json('links.next'))) {

            $parts = parse_url($next);

            self::assertIsArray($parts);
            self::assertArrayHasKey('query', $parts);

            $response = $this->getJson('/api/users?' . $parts['query']);

            $response->assertOk();
        }

        // Five rows over pages of two leaves a single trailing row with no
        // further pages and a null next cursor.
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Evan');

        self::assertFalse($response->json('meta.continue'));
        self::assertNull($response->json('links.next'));
        self::assertIsString($response->json('links.prev'));
    }

    /**
     * Test that a cursor request matching no rows returns the terminal envelope
     * with empty data and null cursors on both sides.
     *
     * @return void
     */
    public function testEmptyCursorPageReturnsTerminalEnvelope(): void
    {
        $filters = json_encode(['id' => ['$gt' => 9999]]);

        $response = $this->getJson('/api/users?pagination=cursor&limit=2&filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');

        self::assertFalse($response->json('meta.continue'));
        self::assertNull($response->json('links.next'));
        self::assertNull($response->json('links.prev'));
    }
}
