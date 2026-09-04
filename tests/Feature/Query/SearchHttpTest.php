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
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\Fixtures\Resources\SearchScopedOrganizationResource;
use Tests\Fixtures\Resources\SearchScopedPostResource;
use Tests\Fixtures\Resources\SearchScopedUserResource;
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
 * One route is served by a resource whose relations are traversable and whose
 * related resources declare searchable columns of their own, so the boundary
 * between the two surfaces is observable on the wire: a filter reaches a
 * related row, a search never does.
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
     * Set up three repository-backed routes: a resource declaring a search
     * surface, one declaring none, and one declaring a search surface beside
     * traversable relations, with a driver registered for the connection under
     * test.
     *
     * The related models are mapped to resources declaring searchable columns
     * of their own, so a search that followed a relation would find everything
     * it needed on the far side of one.
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

        Config::set('api-toolkit.resources.resource_map', [
            Organization::class => SearchScopedOrganizationResource::class,
            Post::class         => SearchScopedPostResource::class,
        ]);

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SearchableFilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SearchableFilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/unsearchable-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/scoped-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SearchScopedUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SearchScopedUserResource::class);
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
        $response->assertJsonPath('error.meta.search.0', 'Every word in the search term must be at least 3 characters.');
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
     * all, which would answer with the whole table. The stack converts empty
     * input to null before the parameter is read, so it is the shape that
     * refuses it rather than the length.
     *
     * @return void
     */
    public function testEmptyTermIsRejected(): void
    {
        $response = $this->getJson('/users?search=');

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta.search.0', 'The search field must be a string.');
    }

    /**
     * Test that a term supplied as an array is rejected on its shape, before
     * anything tries to read it as a term.
     *
     * @return void
     */
    public function testTermSuppliedAsAnArrayIsRejected(): void
    {
        $response = $this->getJson('/users?search[]=smith');

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta.search.0', 'The search field must be a string.');
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
     * Test that a term carried only by a belongs-to related record leaves the
     * owning row unmatched, while the row carrying the term in a column of its
     * own is returned.
     *
     * @return void
     */
    public function testTermCarriedOnlyByABelongsToRelationDoesNotMatchTheOwningRow(): void
    {
        $organization = Organization::create(['name' => 'Fairweather Works', 'slug' => 'fairweather-works']);

        User::create(['name' => 'Renwick', 'email' => 'renwick@example.com', 'status' => 'active', 'organization_id' => $organization->id]);
        User::create(['name' => 'Bellweather', 'email' => 'bell@example.com', 'status' => 'active']);

        $response = $this->getJson('/scoped-users?' . http_build_query(['search' => 'weather']));

        $response->assertOk();

        // Renwick carries the term only through its organization; Bellweather
        // carries it in the root column. Renwick appearing would mean the
        // search had followed the relation.
        self::assertSame(['Bellweather'], $this->names($response));
        $response->assertJsonPath('meta.total', 1);

        // The same relation is reachable by a filter, so the row is absent
        // above because a search stops at the requested resource, not because
        // the related record is missing or unjoinable.
        $filters  = json_encode(['organization' => ['name' => ['$eq' => 'Fairweather Works']]]);
        $filtered = $this->getJson('/scoped-users?' . http_build_query(['filters' => $filters]));

        $filtered->assertOk();
        self::assertSame(['Renwick'], $this->names($filtered));
    }

    /**
     * Test that a term carried only by a has-many related record matches
     * nothing at all, so no row is reached through the relation.
     *
     * @return void
     */
    public function testTermCarriedOnlyByAHasManyRelationMatchesNothing(): void
    {
        $user = User::create(['name' => 'Okonkwo', 'email' => 'okonkwo@example.com', 'status' => 'active']);

        Post::create(['user_id' => $user->id, 'title' => 'notes from the marshland', 'body' => 'a survey of the marshland']);

        $response = $this->getJson('/scoped-users?' . http_build_query(['search' => 'marshland']));

        $response->assertOk();
        self::assertSame([], $this->names($response));
        $response->assertJsonPath('meta.total', 0);

        // The post is attached and its title is reachable by a filter, so the
        // empty result above is the search boundary rather than a row that was
        // never written.
        $filters  = json_encode(['posts' => ['title' => ['$eq' => 'notes from the marshland']]]);
        $filtered = $this->getJson('/scoped-users?' . http_build_query(['filters' => $filters]));

        $filtered->assertOk();
        self::assertSame(['Okonkwo'], $this->names($filtered));
    }

    /**
     * Test that the search predicate is compared against the root columns in
     * place, with no correlated subquery over a relation.
     *
     * The rows answer what a search matches; this answers what it costs. A
     * predicate that reached a relation would arrive as a subquery evaluated
     * once per candidate row, which is the expense the boundary exists to
     * refuse.
     *
     * @return void
     */
    public function testSearchEmitsNoCorrelatedSubqueryOverARelation(): void
    {
        DB::enableQueryLog();

        $response = $this->getJson('/scoped-users?' . http_build_query(['search' => 'smith']));
        $sql      = $this->loggedStatements();

        $response->assertOk();
        self::assertEqualsCanonicalizing(['Highsmith', 'Blacksmith', 'Goldsmith'], $this->names($response));

        self::assertStringContainsString('name like ?', $sql);
        self::assertStringContainsString('email like ?', $sql);
        self::assertStringNotContainsString('exists', $sql);
        self::assertStringNotContainsString('organizations', $sql);
        self::assertStringNotContainsString('posts', $sql);
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

    /**
     * Drain the recorded query log into one identifier-unquoted string, so SQL
     * assertions read the whole request and stay driver-agnostic.
     *
     * @return string
     */
    private function loggedStatements(): string
    {
        $log = DB::getQueryLog();

        DB::disableQueryLog();
        DB::flushQueryLog();

        $statements = array_map(static fn (array $entry): string => $entry['query'], $log);

        return str_replace(['`', '"'], '', implode(' ', $statements));
    }
}
