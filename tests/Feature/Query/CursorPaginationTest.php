<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ProvidesApiEnvelope;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\PageOrder;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for cursor-paginated collections through the kernel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversClass(OrderApplier::class)]
#[CoversClass(PageOrder::class)]
#[CoversClass(QueryParameterValidator::class)]
#[CoversTrait(ProvidesApiEnvelope::class)]
final class CursorPaginationTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /**
     * Set up each test with a cursor-capable users route and ordered rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [FilterableUserResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/users-by-status', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)
                ->withApiCriteria()
                ->addScope(static function (Builder $query): void {
                    $query->getQuery()->orderBy('status', 'desc');
                })
                ->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        // The cursor paginator orders by key, so pages follow creation order
        User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        User::create(['name' => 'Ben', 'email' => 'ben@example.com']);
        User::create(['name' => 'Cara', 'email' => 'cara@example.com']);
        User::create(['name' => 'Dan', 'email' => 'dan@example.com']);
        User::create(['name' => 'Evan', 'email' => 'evan@example.com']);
    }

    /**
     * Test that cursor mode returns the cursor envelope on the first page.
     *
     * @return void
     */
    public function testCursorModeReturnsTheCursorEnvelope(): void
    {
        $response = $this->getJson('/users?pagination=cursor&limit=2');

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
     * Provide pagination modes other than cursor.
     *
     * @return iterable<string, array{string}>
     */
    public static function unknownPaginationModeProvider(): iterable
    {
        yield 'misspelt' => ['pagination=curser'];
        yield 'list' => ['pagination[]=cursor'];
        yield 'malformed' => ['pagination=%FF'];
    }

    /**
     * Test that a pagination mode other than cursor is refused before any read
     * rather than served by offset.
     *
     * @param  string  $query
     * @return void
     */
    #[DataProvider('unknownPaginationModeProvider')]
    public function testUnknownPaginationModeIsRefused(string $query): void
    {
        $statements = $this->recordStatements(fn () => $this->getJson('/users?' . $query . '&limit=2')
            ->assertUnprocessable()
            ->assertJsonPath('error.meta', ['pagination' => ['The selected pagination is invalid.']]));

        self::assertSame([], $statements);
    }

    /**
     * Test that a followed cursor page runs one seek read, with no count and no
     * offset.
     *
     * @return void
     */
    public function testFollowedCursorPageRunsOneSeekRead(): void
    {
        foreach (range(1, 25) as $index) {
            User::create(['name' => sprintf('User %02d', $index), 'email' => 'user' . $index . '@example.com']);
        }

        $second = $this->follow($this->nextLink($this->getJson('/users?pagination=cursor&limit=5&order=name:asc')));
        $third  = $this->nextLink($second);

        $statements = $this->recordStatements(fn () => $this->follow($third));
        $grammar    = DB::connection()->getQueryGrammar();
        $name       = $grammar->wrap('users.name');
        $key        = $grammar->wrap('users.id');

        self::assertStatementCount(1, $statements);
        self::assertStringContainsString(sprintf('where %1$s >= ? and (%1$s > ? or (%1$s = ? and (%2$s > ?)))', $name, $key), $statements[0]);
        self::assertStringEndsWith(sprintf('order by %s asc, %s asc limit 6', $name, $key), $statements[0]);
        self::assertStringNotContainsString('count(', $statements[0]);
        self::assertStringNotContainsString('offset', $statements[0]);
    }

    /**
     * Test that following the next cursor returns the following page.
     *
     * @return void
     */
    public function testFollowingTheNextCursorReturnsTheFollowingPage(): void
    {
        $first = $this->getJson('/users?pagination=cursor&limit=2');

        $first->assertOk();

        $next = $first->json('links.next');

        self::assertIsString($next);

        $parts = parse_url($next);

        self::assertIsArray($parts);
        self::assertArrayHasKey('query', $parts);

        $second = $this->getJson('/users?' . $parts['query']);

        $second->assertOk();
        $second->assertJsonCount(2, 'data');
        $second->assertJsonPath('data.0.name', 'Cara');
        $second->assertJsonPath('data.1.name', 'Dan');

        self::assertTrue($second->json('meta.continue'));

        self::assertIsString($second->json('links.prev'));
    }

    /**
     * Test that following the next cursors to the end returns the trailing row
     * with no further cursor.
     *
     * @return void
     */
    public function testLastCursorPageHasNoNextCursor(): void
    {
        $response = $this->getJson('/users?pagination=cursor&limit=2');

        $response->assertOk();

        while (is_string($next = $response->json('links.next'))) {

            $parts = parse_url($next);

            self::assertIsArray($parts);
            self::assertArrayHasKey('query', $parts);

            $response = $this->getJson('/users?' . $parts['query']);

            $response->assertOk();
        }

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Evan');

        self::assertFalse($response->json('meta.continue'));
        self::assertNull($response->json('links.next'));
        self::assertIsString($response->json('links.prev'));
    }

    /**
     * Test that a cursor token alone, without pagination=cursor, resolves
     * cursor pagination.
     *
     * @return void
     */
    public function testCursorTokenAloneResolvesCursorPagination(): void
    {
        $first = $this->getJson('/users?pagination=cursor&limit=2');

        $first->assertOk();

        $next = $first->json('links.next');

        self::assertIsString($next);

        $parts = parse_url($next);

        self::assertIsArray($parts);
        self::assertArrayHasKey('query', $parts);

        parse_str($parts['query'], $params);

        self::assertArrayHasKey('cursor', $params);
        self::assertIsString($params['cursor']);

        $second = $this->getJson('/users?limit=2&cursor=' . urlencode($params['cursor']));

        $second->assertOk();
        $second->assertJsonCount(2, 'data');
        $second->assertJsonPath('data.0.name', 'Cara');
        $second->assertJsonPath('data.1.name', 'Dan');

        self::assertTrue($second->json('meta.continue'));
        self::assertIsString($second->json('links.prev'));
    }

    /**
     * Test that a cursor request matching no rows returns empty data and null
     * cursors.
     *
     * @return void
     */
    public function testEmptyCursorPageReturnsTerminalEnvelope(): void
    {
        $filters = json_encode(['id' => ['$gt' => 9999]]);

        $response = $this->getJson('/users?pagination=cursor&limit=2&filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');

        self::assertFalse($response->json('meta.continue'));
        self::assertNull($response->json('links.next'));
        self::assertNull($response->json('links.prev'));
    }

    /**
     * Test that rows tied on the order are each paged once, both ways.
     *
     * @return void
     */
    public function testTiedRowsArePagedOnceInEachDirection(): void
    {
        User::query()->update(['name' => 'Same']);

        $pages = [$this->getJson('/users?pagination=cursor&order=name&limit=2')];

        while (is_string($next = end($pages)->json('links.next'))) {
            $pages[] = $this->follow($next);
        }

        self::assertSame([['anna', 'ben'], ['cara', 'dan'], ['evan']], array_map($this->emails(...), $pages));

        $back = [end($pages)];

        while (is_string($prev = end($back)->json('links.prev'))) {
            $back[] = $this->follow($prev);
        }

        self::assertSame([['evan'], ['cara', 'dan'], ['anna', 'ben']], array_map($this->emails(...), $back));
    }

    /**
     * Test that a descending order pages its tied rows by descending key.
     *
     * @return void
     */
    public function testDescendingOrderPagesTiedRowsByDescendingKey(): void
    {
        User::query()->update(['name' => 'Same']);

        $first = $this->getJson('/users?pagination=cursor&order=name:desc&limit=2');
        $next  = $first->json('links.next');

        self::assertIsString($next);
        self::assertSame(['evan', 'dan'], $this->emails($first));
        self::assertSame(['cara', 'ben'], $this->emails($this->follow($next)));
    }

    /**
     * Test that random order is refused on a cursor-paginated read.
     *
     * @return void
     */
    public function testRandomOrderIsRejected(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $response = $this->getJson('/users?pagination=cursor&order=random');

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta', ['order.random' => ['Random order cannot be paged by cursor; use offset pagination.']]);
    }

    /**
     * Test that a column that admits null is refused on a cursor-paginated
     * read.
     *
     * @return void
     */
    public function testNullableColumnIsRejected(): void
    {
        $response = $this->getJson('/users?pagination=cursor&order=created_at');

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta', [
            'order.created_at' => ['The "created_at" key admits null, so it cannot order a cursor-paginated read; use offset pagination.'],
        ]);
    }

    /**
     * Test that a cursor replayed under a different order is refused.
     *
     * @return void
     */
    public function testCursorReplayedUnderADifferentOrderIsRejected(): void
    {
        $next = $this->getJson('/users?pagination=cursor&order=name&limit=2')->json('links.next');

        self::assertIsString($next);

        $cursor   = $this->cursorOf($next);
        $response = $this->getJson('/users?order=id&limit=2&cursor=' . urlencode($cursor));

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta', ['cursor' => ['The cursor does not match the requested order; restart from the first page.']]);
    }

    /**
     * Test that a cursor that does not decode is refused rather than read as
     * the first page.
     *
     * @return void
     */
    public function testUndecodableCursorIsRejected(): void
    {
        $response = $this->getJson('/users?limit=2&cursor=garbage');

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta', ['cursor' => ['The cursor could not be read; restart from the first page.']]);
    }

    /**
     * Test that a cursor carrying a null boundary is refused.
     *
     * @return void
     */
    public function testCursorWithANullBoundaryIsRejected(): void
    {
        $cursor   = (new Cursor(['name' => null, 'users.id' => 2]))->encode();
        $response = $this->getJson('/users?order=name&limit=2&cursor=' . $cursor);

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta', ['cursor' => ['The cursor does not match the requested order; restart from the first page.']]);
    }

    /**
     * Test that a hand-edited cursor whose boundary does not fit its column is
     * refused before it reaches the engine.
     *
     * @return void
     */
    public function testCursorWithABoundaryOfTheWrongTypeIsRejected(): void
    {
        $cursor   = (new Cursor(['name' => 'x', 'users.id' => 'abc']))->encode();
        $response = $this->getJson('/users?order=name&limit=2&cursor=' . $cursor);

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta', ['cursor' => ['The cursor does not match the requested order; restart from the first page.']]);
    }

    /**
     * Test that a repository scope's order is kept ahead of the key.
     *
     * @return void
     */
    public function testRepositoryScopeOrderIsKeptAheadOfTheKey(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        User::query()->whereIn('email', ['ben@example.com', 'dan@example.com'])->update(['status' => 'inactive']);

        $pages = [$this->getJson('/users-by-status?pagination=cursor&limit=2')];

        while (is_string($next = end($pages)->json('links.next'))) {
            $pages[] = $this->follow($next);
        }

        self::assertSame([['dan', 'ben'], ['evan', 'cara'], ['anna']], array_map($this->emails(...), $pages));
    }

    /**
     * Test that a narrowed projection still pages tied rows by the key.
     *
     * @return void
     */
    public function testNarrowedProjectionPagesTiedRowsByTheKey(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        User::query()->whereIn('email', ['anna@example.com', 'ben@example.com', 'cara@example.com'])->update(['name' => 'Same']);

        $pages = [$this->getJson('/users?pagination=cursor&order=name&limit=2&fields[filterable_users]=email')];

        while (is_string($next = end($pages)->json('links.next'))) {
            $pages[] = $this->follow($next);
        }

        self::assertSame([['dan', 'evan'], ['anna', 'ben'], ['cara']], array_map($this->emails(...), $pages));
        end($pages)->assertJsonMissingPath('data.0.name');
    }

    /**
     * Follow a pagination link through the kernel.
     *
     * @param  string  $link
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function follow(string $link): TestResponse
    {
        $parts = parse_url($link);

        self::assertIsArray($parts);
        self::assertArrayHasKey('query', $parts);

        $response = $this->getJson(($parts['path'] ?? '/users') . '?' . $parts['query']);

        $response->assertOk();

        return $response;
    }

    /**
     * Read the next link of the page.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return string
     */
    private function nextLink(TestResponse $response): string
    {
        $next = $response->json('links.next');

        self::assertIsString($next);

        return $next;
    }

    /**
     * Read the cursor token a pagination link carries.
     *
     * @param  string  $link
     * @return string
     */
    private function cursorOf(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $parameters);

        self::assertIsString($parameters['cursor'] ?? null);

        return $parameters['cursor'];
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
