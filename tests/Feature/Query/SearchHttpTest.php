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
 * Seeded terms keep one case, since one supported engine matches patterns
 * case-sensitively.
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
     * Set up search-declaring, search-less and relation-traversing routes with
     * a driver registered for the connection.
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
     * Test that a term matches inside a value, not only at its start.
     *
     * @return void
     */
    public function testSubstringTermMatchesInsideTheColumnValue(): void
    {
        $names = $this->names($this->search('smith'));

        self::assertEqualsCanonicalizing(['Highsmith', 'Blacksmith', 'Goldsmith'], $names);
    }

    /**
     * Test that a prefix-strategy column matches from the start of its own
     * value.
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
     * disjunction.
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

        // Jones would mean the search was absorbed into one filter branch
        self::assertSame(['Goldsmith'], $this->names($response));
        $response->assertJsonPath('meta.total', 1);
    }

    /**
     * Test that a term below the minimum length is rejected.
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
     * Test that an empty term is rejected.
     *
     * The stack converts empty input to null, so it is the shape rule that
     * refuses it.
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
     * Test that a term supplied as an array is rejected on its shape.
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
     * Test that a resource declaring nothing searchable refuses the parameter.
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
     * Test that a term carried only by a belongs-to related record does not
     * match the owning row.
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

        // Renwick carries the term only through its organization
        self::assertSame(['Bellweather'], $this->names($response));
        $response->assertJsonPath('meta.total', 1);

        // The relation is reachable by a filter, so the related record exists
        $filters  = json_encode(['organization' => ['name' => ['$eq' => 'Fairweather Works']]]);
        $filtered = $this->getJson('/scoped-users?' . http_build_query(['filters' => $filters]));

        $filtered->assertOk();
        self::assertSame(['Renwick'], $this->names($filtered));
    }

    /**
     * Test that a term carried only by a has-many related record matches
     * nothing.
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

        // The post is reachable by a filter, so this is the search boundary
        $filters  = json_encode(['posts' => ['title' => ['$eq' => 'notes from the marshland']]]);
        $filtered = $this->getJson('/scoped-users?' . http_build_query(['filters' => $filters]));

        $filtered->assertOk();
        self::assertSame(['Okonkwo'], $this->names($filtered));
    }

    /**
     * Test that the search predicate compares the root columns in place, with
     * no correlated subquery.
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

        // One engine compares as text, so a cast precedes the operator
        self::assertMatchesRegularExpression('/["`]?name["`]?(::text)? like \?/', $sql);
        self::assertMatchesRegularExpression('/["`]?email["`]?(::text)? like \?/', $sql);
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
     * Drain the query log into one identifier-unquoted string.
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
