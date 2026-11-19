<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ProvidesApiEnvelope;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\OffsetPaginator;
use SineMacula\ApiToolkit\Repositories\Concerns\PageOrder;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for offset-paginated collections through the kernel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversClass(OffsetPaginator::class)]
#[CoversClass(PageOrder::class)]
#[CoversTrait(ProvidesApiEnvelope::class)]
final class OffsetPaginationTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

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

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/plain-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        // The paginator orders by primary key, so pages follow creation order
        User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        User::create(['name' => 'Ben', 'email' => 'ben@example.com']);
        User::create(['name' => 'Cara', 'email' => 'cara@example.com']);
        User::create(['name' => 'Dan', 'email' => 'dan@example.com']);
        User::create(['name' => 'Evan', 'email' => 'evan@example.com']);
    }

    /**
     * Test that the second page returns the next slice with the full meta,
     * links and total-count header.
     *
     * @return void
     */
    public function testSecondPageReturnsNextRowsWithLinksMetaAndTotalCountHeader(): void
    {
        $response = $this->getJson('/users?limit=2&page=2');

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data'  => ['*' => ['_type', 'id', 'email', 'name']],
            'meta'  => ['total', 'total_capped', 'count', 'continue'],
            'links' => ['self', 'first', 'prev', 'next', 'last'],
        ]);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Cara');
        $response->assertJsonPath('data.1.name', 'Dan');

        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.total_capped', false);
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
     * Test that the last page reports no further pages and a null next link.
     *
     * @return void
     */
    public function testLastPageReportsContinueFalseAndNullNextLink(): void
    {
        $response = $this->getJson('/users?limit=2&page=3');

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

        $response = $this->getJson('/users');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.continue', true);
    }

    /**
     * Test that a full page runs the read and one count over a constant
     * projection, and nothing else.
     *
     * @return void
     */
    public function testFullPageRunsTheReadAndOneCount(): void
    {
        $this->getJson('/users?limit=2&page=2')->assertOk();

        $statements = $this->recordStatements(fn () => $this->getJson('/users?limit=2&page=2')->assertOk());
        $grammar    = DB::connection()->getQueryGrammar();

        self::assertStatementCount(2, $statements);
        self::assertStringEndsWith('limit 2 offset 2', $statements[0]);
        self::assertStringStartsWith(sprintf('select count(*) as %s from (select 1 from %s ', $grammar->wrap('aggregate'), $grammar->wrap('users')), $statements[1]);
    }

    /**
     * Test that a total past the pages the offset cap allows is flagged and not
     * sent as a header, and that its last link is a page the cap serves.
     *
     * @return void
     */
    public function testTotalPastTheOffsetCapIsFlaggedAndItsLastLinkIsServed(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 4);

        User::create(['name' => 'Fay', 'email' => 'fay@example.com']);
        User::create(['name' => 'Gus', 'email' => 'gus@example.com']);

        $response = $this->getJson('/users?limit=2');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 6);
        $response->assertJsonPath('meta.total_capped', true);
        $response->assertHeaderMissing('Total-Count');

        $link = $response->json('links.last');

        self::assertIsString($link);

        $last = $this->getJson($link);

        $last->assertOk();
        $last->assertJsonPath('data.1.name', 'Fay');
        $last->assertJsonPath('meta.total_capped', true);
        $last->assertJsonPath('meta.continue', false);

        self::assertNull($last->json('links.next'));
    }

    /**
     * Test that a page past the offset cap is refused when the repository pages
     * without the API criteria.
     *
     * @return void
     */
    public function testPagePastTheOffsetCapIsRefusedWithoutTheCriteria(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 4);

        $response = $this->getJson('/plain-users?limit=2&page=4');

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta.reason', 'max_offset');
        $response->assertJsonPath('error.meta.limit', 3);
        $response->assertJsonPath('error.meta.actual', 4);
    }

    /**
     * Test that rows tied on the order are each paged once.
     *
     * @return void
     */
    public function testTiedRowsArePagedOnce(): void
    {
        User::query()->update(['name' => 'Same']);

        $pages = array_map(fn (int $page): array => $this->emails($this->getJson('/users?order=name&limit=2&page=' . $page)), [1, 2, 3]);

        self::assertSame([['anna', 'ben'], ['cara', 'dan'], ['evan']], $pages);
    }

    /**
     * Test that rows tied on a descending order are paged by descending key.
     *
     * @return void
     */
    public function testTiedRowsOnADescendingOrderArePagedByDescendingKey(): void
    {
        User::query()->update(['name' => 'Same']);

        $pages = array_map(fn (int $page): array => $this->emails($this->getJson('/users?order=name:desc&limit=2&page=' . $page)), [1, 2, 3]);

        self::assertSame([['evan', 'dan'], ['cara', 'ben'], ['anna']], $pages);
    }

    /**
     * Test that a read with no order pages in key order, whatever order the
     * engine stores the rows in.
     *
     * @return void
     */
    public function testUnorderedReadPagesInKeyOrder(): void
    {
        // Rewritten rows move to the end of a heap-organised table
        User::query()->whereIn('email', ['anna@example.com', 'ben@example.com'])->update(['status' => 'inactive']);

        self::assertSame(['anna', 'ben'], $this->emails($this->getJson('/users?limit=2')));
    }

    /**
     * Test that a descending order is honoured.
     *
     * @return void
     */
    public function testDescendingOrderIsHonoured(): void
    {
        self::assertSame(['evan', 'dan'], $this->emails($this->getJson('/users?order=name:desc&limit=2')));
    }

    /**
     * Test that the appended key does not count against the order-key cap.
     *
     * @return void
     */
    public function testAppendedKeyDoesNotCountAgainstTheOrderKeyCap(): void
    {
        Config::set('api-toolkit.query_cost.max_order_keys', 1);

        self::assertSame(['anna', 'ben'], $this->emails($this->getJson('/users?order=name&limit=2')));
    }

    /**
     * Read the local part of each email on the page, in order.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return array<int, string>
     */
    private function emails(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<int, array{email: string}> $data */
        $data = $response->json('data');

        return array_map(static fn (array $user): string => strstr($user['email'], '@', true) ?: '', $data);
    }
}
