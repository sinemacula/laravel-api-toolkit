<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException;
use SineMacula\ApiToolkit\Exceptions\UnservableSearchException;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\Fixtures\Resources\SearchableUserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Feature tests proving an unservable search fails closed over real HTTP.
 *
 * Every deployment defect the search layer refuses - an unregistered
 * connection, a strategy the driver does not implement, a strategy no index is
 * proved to serve on a connection that does not waive the proof, a strategy the
 * catalogue says no index is behind, and strategies the driver cannot serve
 * beside one another - is a plain runtime failure rather than a client mistake,
 * so it reaches the client as the unhandled 500 envelope and, above all,
 * without rows. A refusal that answered 200 with the unnarrowed table is the
 * silent failure the whole layer exists to remove, so each test asserts the
 * response carries no data at all, and the same request against a serviceable
 * deployment is driven alongside them so a route that failed for its own
 * reasons could not be mistaken for one of these refusals.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SearchApplier::class)]
#[CoversClass(SearchDriverRegistry::class)]
#[CoversClass(UnservableSearchException::class)]
#[CoversClass(MissingSearchDriverException::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class SearchDeploymentFailureHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var string The term every request in this suite searches for */
    private const string TERM = 'smith';

    /** @var array<int, string> The names seeded behind both routes */
    private const array ROWS = ['Highsmith', 'Blacksmith', 'Goldsmith', 'Jones'];

    /**
     * Set up a serviceable deployment behind two repository-backed routes, one
     * resource declaring a single match strategy and one declaring two, so each
     * test breaks exactly one part of it.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('app.debug', false);
        Config::set('api-toolkit.exceptions.include_debug_info', true);
        Config::set('api-toolkit.search.unverified_connections', [$this->connection()]);

        $this->useDriver(new PatternSearchDriver);

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SearchableFilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SearchableFilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/multi-strategy-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SearchableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SearchableUserResource::class);
        });

        User::create(['name' => 'Highsmith', 'email' => 'highsmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Blacksmith', 'email' => 'blacksmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Goldsmith', 'email' => 'goldsmith@example.com', 'status' => 'active']);
        User::create(['name' => 'Jones', 'email' => 'jonathan@example.com', 'status' => 'inactive']);
    }

    /**
     * Test that a serviceable deployment answers the same request with the
     * narrowed rows, so the refusals below are attributable to the defect each
     * one introduces rather than to the route.
     *
     * @return void
     */
    public function testServiceableDeploymentAnswersTheSameRequestWithNarrowedRows(): void
    {
        $response = $this->search('/users');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);

        self::assertEqualsCanonicalizing(
            ['Highsmith', 'Blacksmith', 'Goldsmith'],
            array_column((array) $response->json('data'), 'name'),
        );
    }

    /**
     * Test that a search on a connection carrying no registered driver fails as
     * a server error rather than dropping the narrowing predicate and answering
     * with the whole table.
     *
     * @return void
     */
    public function testConnectionWithNoRegisteredDriverFailsClosed(): void
    {
        $this->app?->instance(SearchDriverRegistry::class, new SearchDriverRegistry);

        $this->assertFailsClosed(
            $this->search('/users'),
            MissingSearchDriverException::class,
            sprintf(
                'No search driver is registered for the "%s" connection. Register one to serve a search on that connection.',
                $this->connection(),
            ),
        );
    }

    /**
     * Test that a strategy the registered driver does not implement fails as a
     * server error rather than being served by some other match shape.
     *
     * @return void
     */
    public function testStrategyTheDriverDoesNotImplementFailsClosed(): void
    {
        $this->useDriver(new PatternSearchDriver([SearchStrategy::SUBSTRING]));

        $this->assertFailsClosed(
            $this->search('/multi-strategy-users'),
            UnservableSearchException::class,
            sprintf(
                'The search driver registered for the "%s" connection does not implement the "exact" match strategy this resource declares.',
                $this->connection(),
            ),
        );
    }

    /**
     * Test that a driver which can prove nothing about the indexes behind a
     * strategy fails as a server error on a connection that does not waive the
     * proof.
     *
     * @return void
     */
    public function testUnprovenIndexBackingFailsClosedWhereTheConnectionDoesNotWaiveIt(): void
    {
        Config::set('api-toolkit.search.unverified_connections', []);

        $this->assertFailsClosed(
            $this->search('/users'),
            UnservableSearchException::class,
            sprintf(
                'The search driver registered for the "%s" connection cannot prove an index serves the "substring" match strategy, so the search would scan the table. '
                . 'List the connection under api-toolkit.search.unverified_connections to serve it regardless.',
                $this->connection(),
            ),
        );
    }

    /**
     * Test that a catalogue carrying no index behind the declared strategy
     * fails as a server error naming what is missing, rather than answering
     * from a full table scan.
     *
     * @return void
     */
    public function testMissingIndexFailsClosed(): void
    {
        Config::set('api-toolkit.search.unverified_connections', []);

        $this->useDriver(new PatternSearchDriver(null, true, ['no trigram index over "name"']));

        $this->assertFailsClosed(
            $this->search('/users'),
            UnservableSearchException::class,
            sprintf(
                'The "%s" connection carries no index serving the "substring" match strategy this resource declares, '
                . 'so the search would scan the table: no trigram index over "name".',
                $this->connection(),
            ),
        );
    }

    /**
     * Test that strategies the driver cannot serve beside one another fail as a
     * server error carrying the whole reason, before any predicate is emitted.
     *
     * @return void
     */
    public function testStrategiesTheDriverCannotServeTogetherFailClosed(): void
    {
        $this->useDriver(new PatternSearchDriver(null, false, [], 'they cannot share a disjunction here'));

        $this->assertFailsClosed(
            $this->search('/multi-strategy-users'),
            UnservableSearchException::class,
            sprintf(
                'The search driver registered for the "%s" connection cannot serve the match strategies this resource declares together, '
                . 'because they cannot share a disjunction here.',
                $this->connection(),
            ),
        );
    }

    /**
     * Test that the refusal reaches a client of a production deployment as the
     * unhandled envelope alone, carrying neither the rows the search was asked
     * for nor the internals of the defect that refused it.
     *
     * @return void
     */
    public function testRefusalCarriesNeitherRowsNorInternalsWithDebugMetaDisabled(): void
    {
        Config::set('api-toolkit.exceptions.include_debug_info', false);
        Config::set('api-toolkit.search.unverified_connections', []);

        $response = $this->search('/users');

        $response->assertStatus(500);
        $response->assertExactJson([
            'error' => [
                'status' => 500,
                'code'   => 10001,
                'title'  => 'Unknown Error',
                'detail' => 'Oh no! Something has gone wrong!',
            ],
        ]);

        $this->assertCarriesNoRows($response);
    }

    /**
     * Assert the response is the unhandled server-error envelope, that the
     * named defect is what produced it, and that it carries no rows.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @param  class-string<\Throwable>  $exception
     * @param  string  $message
     * @return void
     */
    private function assertFailsClosed(TestResponse $response, string $exception, string $message): void
    {
        $response->assertStatus(500);
        $response->assertJsonPath('error.status', 500);
        $response->assertJsonPath('error.code', 10001);
        $response->assertJsonPath('error.title', 'Unknown Error');
        $response->assertJsonPath('error.detail', 'Oh no! Something has gone wrong!');
        $response->assertJsonPath('error.meta.exception', $exception);
        $response->assertJsonPath('error.meta.message', $message);

        $this->assertCarriesNoRows($response);
    }

    /**
     * Assert the response carries no part of the table the search was asked to
     * narrow.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @return void
     */
    private function assertCarriesNoRows(TestResponse $response): void
    {
        $response->assertJsonMissingPath('data');

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);

        foreach (self::ROWS as $name) {
            self::assertStringNotContainsString($name, $content);
        }
    }

    /**
     * Register the given driver for the connection under test.
     *
     * @param  \Tests\Fixtures\Search\PatternSearchDriver  $driver
     * @return void
     */
    private function useDriver(PatternSearchDriver $driver): void
    {
        $this->app?->make(SearchDriverRegistry::class)->override($this->connection(), $driver);
    }

    /**
     * Issue the search request against the given route.
     *
     * @param  string  $path
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function search(string $path): TestResponse
    {
        return $this->getJson($path . '?' . http_build_query(['search' => self::TERM]));
    }

    /**
     * Return the driver name of the connection the suite runs against.
     *
     * @return string
     */
    private function connection(): string
    {
        return DB::connection()->getDriverName();
    }
}
