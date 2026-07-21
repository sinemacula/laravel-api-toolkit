<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
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
 * Feature tests for offset-paginated collections through the kernel.
 *
 * Driving `page=` over the length-aware paginator returns the requested
 * slice of rows alongside the full meta block (total, count, continue), the
 * self, first, prev, next, and last links, and the total-count response
 * header. The terminal page reports no further pages and a null next link,
 * and the configured default limit applies when the client supplies none.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversTrait(ProvidesApiEnvelope::class)]
final class OffsetPaginationTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a repository-backed users route and seeded rows.
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

        // Created in ascending id order; the paginator orders by the primary
        // key by default, so page order follows creation order.
        User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        User::create(['name' => 'Ben', 'email' => 'ben@example.com']);
        User::create(['name' => 'Cara', 'email' => 'cara@example.com']);
        User::create(['name' => 'Dan', 'email' => 'dan@example.com']);
        User::create(['name' => 'Evan', 'email' => 'evan@example.com']);
    }

    /**
     * Test that the second offset page returns the next slice of rows with the
     * full meta block, the complete links block, and the total-count header.
     *
     * @return void
     */
    public function testSecondPageReturnsNextRowsWithLinksMetaAndTotalCountHeader(): void
    {
        $response = $this->getJson('/api/users?limit=2&page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Cara');
        $response->assertJsonPath('data.1.name', 'Dan');

        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.continue', true);

        $response->assertHeader('Total-Count', '5');

        self::assertIsString($response->json('links.self'));
        self::assertIsString($response->json('links.first'));
        self::assertIsString($response->json('links.prev'));
        self::assertIsString($response->json('links.next'));
        self::assertIsString($response->json('links.last'));

        self::assertStringContainsString('page=2', $response->json('links.self'));
        self::assertStringContainsString('page=1', $response->json('links.first'));
        self::assertStringContainsString('page=1', $response->json('links.prev'));
        self::assertStringContainsString('page=3', $response->json('links.next'));
        self::assertStringContainsString('page=3', $response->json('links.last'));
    }

    /**
     * Test that the last offset page reports no further pages and a null next
     * link while the prev link points at the preceding page.
     *
     * @return void
     */
    public function testLastPageReportsContinueFalseAndNullNextLink(): void
    {
        $response = $this->getJson('/api/users?limit=2&page=3');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Evan');

        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('meta.continue', false);

        self::assertNull($response->json('links.next'));
        self::assertIsString($response->json('links.prev'));
        self::assertStringContainsString('page=2', $response->json('links.prev'));
    }

    /**
     * Test that the configured default limit applies when the client supplies
     * none.
     *
     * @return void
     */
    public function testDefaultLimitAppliesWhenNoLimitSupplied(): void
    {
        Config::set('api-toolkit.parser.defaults.limit', 2);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.continue', true);
    }
}
