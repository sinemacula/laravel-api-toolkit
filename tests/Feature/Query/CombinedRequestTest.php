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
 * Feature test combining every read modifier in a single request.
 *
 * Under the default allowlist posture a real request narrows the fieldset,
 * applies a declared filter, orders by a declared sortable column, and
 * truncates with a page limit. The four modifiers are proven to apply together:
 * the fieldset drops undeclared keys, the filtered total excludes non-matching
 * rows, the ordering fixes row positions, and the limit caps the page while the
 * meta still reports the filtered total.
 *
 * A second route adds the search surface to that combination, so one request
 * carries a term, a filter, a two-column sort, a sparse fieldset and a page
 * beyond the first. The term is applied first and the base-table projection is
 * narrowed last, so the search has to reach a column the fieldset never renders
 * and the paginator's own count query has to carry the search group beside the
 * filter. Each is a wrong answer no single-modifier test can see.
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
     * Set up each test with two repository-backed users routes and seeded rows.
     *
     * The first route renders a resource declaring no search surface: four rows
     * carry a retained name and two carry an excluded name that sorts ahead of
     * every retained row, so a missing filter would surface them first under
     * the descending sort. The second renders a resource declaring searchable
     * columns, served by a driver registered for the connection under test.
     * Column narrowing is pinned on, since it is the step that runs last and
     * the search predicate has to survive it.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        $connection = DB::connection()->getDriverName();

        $this->app?->make(SearchDriverRegistry::class)->override($connection, new PatternSearchDriver);

        Config::set('api-toolkit.search.unverified_connections', [$connection]);
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
     * Test that a fieldset, a filter, a sort, and a limit apply together in one
     * request.
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

        // Limit applied: two of the four matching rows are returned.
        $response->assertJsonCount(2, 'data');

        // Sort applied: descending by name over the filtered set.
        $response->assertJsonPath('data.0.name', 'Delta');
        $response->assertJsonPath('data.1.name', 'Charlie');

        // Filter applied: the meta total reflects the four retained rows only,
        // and the two excluded rows never reach the top of the sort.
        $response->assertJsonPath('meta.total', 4);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.continue', true);

        // Fieldset applied: the requested key is present and the undeclared key
        // is absent.
        $record = $response->json('data.0');

        self::assertIsArray($record);
        self::assertArrayHasKey('name', $record);
        self::assertArrayNotHasKey('email', $record);
    }

    /**
     * Test that a search, a filter, a two-column sort, a sparse fieldset and a
     * page beyond the first apply together in one request.
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

        // Limit and page applied: the second slice of the matched set holds the
        // two rows sharing a name, which trail one name match and one match
        // made on the email alone.
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Coppersmith');
        $response->assertJsonPath('data.1.name', 'Coppersmith');

        // Secondary sort applied: the tied rows are ordered by descending id,
        // so the later-created row leads the page.
        self::assertGreaterThan($response->json('data.1.id'), $response->json('data.0.id'));

        // The count query carries the search group beside the filter: the term
        // alone matches six rows and the filter alone matches twelve.
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.continue', true);

        $next = $response->json('links.next');

        self::assertIsString($next);
        self::assertStringContainsString('page=3', $next);

        // Fieldset applied: neither the searched email nor the filtered status
        // is rendered.
        $record = $response->json('data.0');

        self::assertIsArray($record);
        self::assertArrayHasKey('name', $record);
        self::assertArrayNotHasKey('email', $record);
        self::assertArrayNotHasKey('status', $record);
    }

    /**
     * Test that the term still matches a column the narrowed projection leaves
     * out of the base-table select.
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

        // The select is narrowed to the rendered fieldset and the safety set,
        // so the searched email column is never projected.
        self::assertStringContainsString('name', $projection);
        self::assertStringNotContainsString('email', $projection);
        self::assertStringNotContainsString('*', $projection);
    }

    /**
     * Seed the rows the search-composed requests read.
     *
     * Five rows match the term and the filter together: one carries the term in
     * its name, two share a name carrying it so the secondary sort decides
     * their order, and two carry it in the email alone, which a name-only
     * fieldset never renders. Three more are held back, one by the filter, one
     * by the term, and one by both, and each of them sorts inside the matching
     * set so a dropped modifier moves the page rather than only the total. The
     * term appears in the same case in every matching value, since one
     * supported engine matches patterns case-sensitively and another does not.
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
     * Get the projected column list of the hydrating users select from the
     * recorded query log.
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
