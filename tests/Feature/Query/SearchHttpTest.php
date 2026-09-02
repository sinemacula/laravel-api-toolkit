<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Feature tests driving the search parameter over real HTTP requests.
 *
 * A term travels the whole path a consuming application exercises: parsed from
 * the query string, bounded, applied through the criteria layer against the
 * connection's registered driver, and rendered as narrowed rows. The rows are
 * chosen so the term appears in the same case on every supported engine, since
 * one of them matches patterns case-sensitively and another does not.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(QueryParameterExtractor::class)]
#[CoversClass(QueryParameterValidator::class)]
#[CoversClass(SearchApplier::class)]
final class SearchHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up two repository-backed routes, one resource declaring a search
     * surface and one declaring none, with a driver registered for the
     * connection under test.
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

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SearchableFilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SearchableFilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/unsearchable-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Highsmith', 'email' => 'highsmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Blacksmith', 'email' => 'blacksmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Goldsmith', 'email' => 'goldsmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Jones', 'email' => 'jonathan@example.com', 'status' => 'inactive']);
    }

    /**
     * Test that a term matches inside a value rather than only at its start,
     * which is the whole reason the substring strategy exists.
     *
     * @return void
     */
    public function testSubstringTermMatchesInsideTheColumnValue(): void
    {
        $names = $this->names($this->search('smith'));

        self::assertEqualsCanonicalizing(['Highsmith', 'Blacksmith', 'Goldsmith'], $names);
    }

    /**
     * Test that a column declared under the prefix strategy matches from the
     * start of its own value, so one term reaches every declared strategy.
     *
     * @return void
     */
    public function testPrefixTermMatchesAColumnDeclaredUnderThatStrategy(): void
    {
        $names = $this->names($this->search('jonat'));

        self::assertSame(['Jones'], $names);
    }

    /**
     * Test that the search narrows a request carrying a root-level filter
     * disjunction, which cannot escape the search group and widen the result.
     *
     * @return void
     */
    public function testSearchNarrowsAlongsideARootLevelOrFilter(): void
    {
        $filters = json_encode([
            '$or' => [
                'name'   => ['$eq' => 'Goldsmith'],
                'status' => ['$eq' => 'inactive'],
            ],
        ]);

        $response = $this->getJson('/users?' . http_build_query(['search' => 'smith', 'filters' => $filters]));

        $response->assertOk();

        // The disjunction alone matches Goldsmith and Jones; the search alone
        // matches the three smiths. Jones appearing would mean the search was
        // absorbed into one branch of the filter rather than ANDed with it.
        self::assertSame(['Goldsmith'], $this->names($response));
        $response->assertJsonPath('meta.total', 1);
    }

    /**
     * Test that a term below the minimum length is rejected rather than
     * answered from an index that cannot serve it.
     *
     * @return void
     */
    public function testTermBelowTheMinimumLengthIsRejected(): void
    {
        $response = $this->getJson('/users?search=sm');

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
        $response->assertJsonPath('error.meta.search.0', 'The search term must be at least 3 characters.');
    }

    /**
     * Test that a term at exactly the minimum length is accepted.
     *
     * @return void
     */
    public function testTermAtTheMinimumLengthIsAccepted(): void
    {
        $this->search('gol')->assertOk();
    }

    /**
     * Test that an empty term is rejected rather than treated as no search at
     * all, which would answer with the whole table.
     *
     * Which bound catches it depends on the application: a stack that converts
     * empty input to null fails the shape, one that does not fails the length.
     * Both refuse, and both name the parameter.
     *
     * @return void
     */
    public function testEmptyTermIsRejected(): void
    {
        $response = $this->getJson('/users?search=');

        $response->assertStatus(422);

        self::assertArrayHasKey('search', (array) $response->json('error.meta'));
    }

    /**
     * Test that a term supplied as an array is rejected on its shape.
     *
     * @return void
     */
    public function testTermSuppliedAsAnArrayIsRejected(): void
    {
        $response = $this->getJson('/users?search[]=smith');

        $response->assertStatus(422);

        self::assertArrayHasKey('search', (array) $response->json('error.meta'));
    }

    /**
     * Test that a resource declaring nothing searchable refuses the parameter
     * rather than answering it with an unnarrowed list.
     *
     * @return void
     */
    public function testResourceDeclaringNoSearchableColumnRefusesTheParameter(): void
    {
        $response = $this->getJson('/unsearchable-users?search=smith');

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta.search.0', 'The search parameter is not permitted for this resource.');
    }

    /**
     * Test that a request carrying no term is answered with every row.
     *
     * @return void
     */
    public function testRequestWithoutATermIsUnnarrowed(): void
    {
        $response = $this->getJson('/users');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 4);
    }

    /**
     * Issue a search request against the searchable route.
     *
     * @param  string  $term
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function search(string $term): TestResponse
    {
        return $this->getJson('/users?' . http_build_query(['search' => $term]));
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
