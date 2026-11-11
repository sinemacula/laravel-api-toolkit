<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\CombinedSearchUserResource;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Feature tests combining every read modifier in a single request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(OrderApplier::class)]
#[CoversClass(SearchApplier::class)]
#[CoversClass(ColumnProjectionApplier::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(ApiResourceCollection::class)]
final class CombinedRequestTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up two users routes, one plain and one searchable with column
     * narrowing on, and seed rows.
     *
     * The excluded rows sort ahead of every retained row, so a missing filter
     * would surface them first.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        $connection = DB::connection();

        $this->app?->make(SearchDriverRegistry::class)->override($connection->getDriverName(), new PatternSearchDriver);

        Config::set('api-toolkit.search.unverified_connections', [$connection->getName()]);
        Config::set('api-toolkit.resources.narrow_columns', true);

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/searchable-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(CombinedSearchUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, CombinedSearchUserResource::class);
        });

        User::create(['name' => 'Alpha', 'email' => 'alpha@keep.com']);
        User::create(['name' => 'Bravo', 'email' => 'bravo@keep.com']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@keep.com']);
        User::create(['name' => 'Delta', 'email' => 'delta@keep.com']);
        User::create(['name' => 'Yankee', 'email' => 'yankee@drop.com']);
        User::create(['name' => 'Zulu', 'email' => 'zulu@drop.com']);

        $this->seedSearchableRows();
    }

    /**
     * Test that a fieldset, a filter, a sort and a limit apply together.
     *
     * @return void
     */
    public function testFieldsFilterSortAndLimitApplyTogether(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields'  => ['filterable_users' => 'name'],
            'filters' => json_encode(['name' => ['$in' => ['Alpha', 'Bravo', 'Charlie', 'Delta']]]),
            'order'   => 'name:desc',
            'limit'   => 2,
        ]));

        $response->assertOk();

        $response->assertJsonCount(2, 'data');

        $response->assertJsonPath('data.0.name', 'Delta');
        $response->assertJsonPath('data.1.name', 'Charlie');

        // Without the filter the excluded rows would lead the descending sort
        $response->assertJsonPath('meta.total', 4);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.continue', true);

        $record = $response->json('data.0');

        self::assertIsArray($record);
        self::assertArrayHasKey('name', $record);
        self::assertArrayNotHasKey('email', $record);
    }

    /**
     * Test that a search, a filter, a two-column sort, a fieldset and a later
     * page apply together.
     *
     * @return void
     */
    public function testSearchFilterSortFieldsAndSecondPageApplyTogether(): void
    {
        $response = $this->getJson('/searchable-users?' . http_build_query([
            'search'  => 'smith',
            'filters' => json_encode(['status' => ['$eq' => 'active']]),
            'fields'  => ['combined_search_users' => 'name'],
            'order'   => 'name:asc,id:desc',
            'limit'   => 2,
            'page'    => 2,
        ]));

        $response->assertOk();

        // The second page holds the two rows sharing a name
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Coppersmith');
        $response->assertJsonPath('data.1.name', 'Coppersmith');

        // The tied rows are ordered by descending id
        self::assertGreaterThan($response->json('data.1.id'), $response->json('data.0.id'));

        // The term alone matches six rows and the filter alone twelve
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.continue', true);

        $next = $response->json('links.next');

        self::assertIsString($next);
        self::assertStringContainsString('page=3', $next);

        $record = $response->json('data.0');

        self::assertIsArray($record);
        self::assertArrayHasKey('name', $record);
        self::assertArrayNotHasKey('email', $record);
        self::assertArrayNotHasKey('status', $record);
    }

    /**
     * Test that the term still matches a column the narrowed projection leaves
     * out.
     *
     * @return void
     */
    public function testSearchMatchesAColumnTheNarrowedProjectionOmits(): void
    {
        DB::enableQueryLog();

        $response = $this->getJson('/searchable-users?' . http_build_query([
            'search'  => 'smith',
            'filters' => json_encode(['status' => ['$eq' => 'active']]),
            'fields'  => ['combined_search_users' => 'name'],
            'order'   => 'name:asc,id:desc',
        ]));

        $projection = $this->baseSelectList();

        $response->assertOk();

        // Bexley and Wexford carry the term in the email alone.
        self::assertSame(['Ashsmith', 'Bexley', 'Coppersmith', 'Coppersmith', 'Wexford'], $this->names($response));

        self::assertStringContainsString('name', $projection);
        self::assertStringNotContainsString('email', $projection);
        self::assertStringNotContainsString('*', $projection);
    }

    /**
     * Seed the rows the search-composed requests read.
     *
     * Each held-back row sorts inside the matching set, so a dropped modifier
     * moves the page and not only the total.
     *
     * @return void
     */
    private function seedSearchableRows(): void
    {
        User::create(['name' => 'Ashsmith', 'email' => 'ash@example.com', 'status' => 'active']);
        User::create(['name' => 'Bexley', 'email' => 'bexley.smith@example.com', 'status' => 'active']);
        User::create(['name' => 'Coppersmith', 'email' => 'copper-one@example.com', 'status' => 'active']);
        User::create(['name' => 'Coppersmith', 'email' => 'copper-two@example.com', 'status' => 'active']);
        User::create(['name' => 'Wexford', 'email' => 'wexford.smith@example.com', 'status' => 'active']);
        User::create(['name' => 'Blacksmith', 'email' => 'black@example.com', 'status' => 'inactive']);
        User::create(['name' => 'Cooper', 'email' => 'cooper@example.com', 'status' => 'active']);
        User::create(['name' => 'Aaron', 'email' => 'aaron@example.com', 'status' => 'inactive']);
    }

    /**
     * Get the projected column list of the hydrating users select.
     *
     * @return string
     */
    private function baseSelectList(): string
    {
        $statements = array_column(DB::getQueryLog(), 'query');

        DB::disableQueryLog();
        DB::flushQueryLog();

        foreach ($statements as $statement) {

            $sql = str_replace(['`', '"'], '', $statement);

            if (preg_match('/^select (.+) from users\b/', $sql, $matches) !== 1) {
                continue;
            }

            // Skip the paginator's own row-count query.
            if (!str_contains($matches[1], 'aggregate')) {
                return $matches[1];
            }
        }

        return '';
    }

    /**
     * Extract the name column from a response data payload.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @return array<int, string>
     */
    private function names(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'name');
    }
}
