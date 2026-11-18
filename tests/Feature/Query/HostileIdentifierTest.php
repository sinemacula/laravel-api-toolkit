<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QuerySelectionValidator;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Feature tests for identifiers built to escape the query allowlists.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
#[CoversClass(QuerySelectionValidator::class)]
final class HostileIdentifierTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /**
     * Set up a searchable, filterable users index over one user.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();

        $this->app?->make(SearchDriverRegistry::class)->override($connection->getDriverName(), new PatternSearchDriver);

        Config::set('api-toolkit.search.unverified_connections', [$connection->getName()]);
        Config::set(ResourceDiscovery::RESOURCES_KEY, [SearchableFilterableUserResource::class, PostResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SearchableFilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SearchableFilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret', 'status' => 'active']);
    }

    /**
     * Provide hostile identifiers in every parameter that names a column, with
     * the key each is refused on.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function hostileIdentifierProvider(): iterable
    {
        $type = 'searchable_filterable_users';

        yield 'an order key closing the identifier' => [['order' => 'name`;--'], 'order.name`;--'];
        yield 'an order key with a JSON path' => [['order' => 'name->x'], 'order.name->x'];
        yield 'an order key on a qualified column' => [['order' => 'users.password'], 'order.users.password'];
        yield 'an order key holding a subquery' => [['order' => '(select 1)'], 'order.(select 1)'];
        yield 'a filter key with a JSON path' => [['filters' => '{"email->x":"a"}'], 'filters.email->x'];
        yield 'a filter key on a qualified column' => [['filters' => '{"users.password":"secret"}'], 'filters.users.password'];
        yield 'a filter key breaking the quoting' => [['filters' => '{"name\" OR 1=1 --":"a"}'], 'filters.name" OR 1=1 --'];
        yield 'a nested filter key on a qualified column' => [['filters' => '{"$or":[{"users.password":"secret"}]}'], 'filters.users.password'];
        yield 'a field naming a hidden column' => [['fields' => [$type => 'id,password']], 'fields.' . $type];
        yield 'a field closing the identifier' => [['fields' => [$type => '`x`']], 'fields.' . $type];
        yield 'a wildcard field' => [['fields' => [$type => '*']], 'fields.' . $type];
        yield 'an aggregate carrying an alias and a comment' => [['aggregates' => [$type => 'posts as x;--']], 'aggregates.' . $type];
    }

    /**
     * Test that a hostile identifier is refused on the key it was sent under,
     * before any statement runs.
     *
     * @param  array<string, mixed>  $query
     * @param  string  $key
     * @return void
     */
    #[DataProvider('hostileIdentifierProvider')]
    public function testHostileIdentifierIsRefusedBeforeAnyStatement(array $query, string $key): void
    {
        $statements = $this->recordStatements(function () use ($query, $key): void {

            $response = $this->getJson('/users?' . http_build_query($query));

            $response->assertUnprocessable();

            self::assertSame([$key], array_keys((array) $response->json('error.meta')));
        });

        self::assertStatementCount(0, $statements);
    }

    /**
     * Test that a hostile search term is only ever bound, never spliced into
     * the statement, and selects no undeclared column.
     *
     * @return void
     */
    public function testHostileSearchTermIsBoundNotSpliced(): void
    {
        $statements = $this->recordStatements(function (): void {
            $this->getJson('/users?' . http_build_query(['search' => 'smith\')) UNION SELECT password FROM users;']))
                ->assertOk()
                ->assertJsonCount(0, 'data');
        });

        self::assertNotSame([], $statements);

        foreach ($statements as $sql) {
            self::assertStringNotContainsString('UNION', $sql);
            self::assertStringNotContainsString('password', $sql);
        }
    }
}
