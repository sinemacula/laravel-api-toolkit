<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Concerns\OffsetPaginator;
use SineMacula\ApiToolkit\Repositories\Pagination\CappedLengthAwarePaginator;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\TestCase;

/**
 * Tests for the OffsetPaginator concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(OffsetPaginator::class)]
final class OffsetPaginatorTest extends TestCase
{
    use RecordsStatements;

    /** @var string The configuration key of the offset cap */
    private const string MAX_OFFSET = 'api-toolkit.query_cost.max_offset';

    /**
     * Test that a short first page derives its total without counting.
     *
     * @return void
     */
    public function testShortFirstPageDerivesItsTotalWithoutACount(): void
    {
        $this->seedUsers(3);

        [$paginator, $statements] = $this->paginateRecorded(1, 5);

        self::assertStatementCount(1, $statements);
        self::assertSame(3, $paginator->total());
        self::assertCount(3, $paginator->items());
    }

    /**
     * Test that a short last page derives its total without counting.
     *
     * @return void
     */
    public function testShortLastPageDerivesItsTotalWithoutACount(): void
    {
        $this->seedUsers(5);

        [$paginator, $statements] = $this->paginateRecorded(3, 2);

        self::assertStatementCount(1, $statements);
        self::assertSame(5, $paginator->total());
        self::assertCount(1, $paginator->items());
        self::assertFalse($paginator->hasMorePages());
    }

    /**
     * Test that an empty first page reports no matches without counting.
     *
     * @return void
     */
    public function testEmptyFirstPageReportsNoMatchesWithoutACount(): void
    {
        [$paginator, $statements] = $this->paginateRecorded(1, 5);

        self::assertStatementCount(1, $statements);
        self::assertSame(0, $paginator->total());
    }

    /**
     * Test that a full page is counted, over a constant projection and without
     * the page's order.
     *
     * @return void
     */
    public function testFullPageIsCounted(): void
    {
        $this->seedUsers(4);

        [$paginator, $statements] = $this->paginateRecorded(1, 2);

        self::assertStatementCount(2, $statements);
        self::assertStringStartsWith(sprintf('select count(*) as %s from (select 1 from ', DB::connection()->getQueryGrammar()->wrap('aggregate')), $statements[1]);
        self::assertStringNotContainsString('order by', $statements[1]);
        self::assertSame(4, $paginator->total());
        self::assertSame('http://localhost?page=2', $paginator->nextPageUrl());
    }

    /**
     * Test that an empty page past the end takes the count as it is.
     *
     * @return void
     */
    public function testEmptyPagePastTheEndTakesTheCountAsItIs(): void
    {
        $this->seedUsers(3);

        [$paginator, $statements] = $this->paginateRecorded(3, 2);

        self::assertStatementCount(2, $statements);
        self::assertSame(3, $paginator->total());
        self::assertSame([], $paginator->items());
    }

    /**
     * Test that a full page reports at least the rows it read, even when the
     * count that follows sees fewer.
     *
     * @return void
     */
    public function testFullPageReportsAtLeastTheRowsItRead(): void
    {
        $this->seedUsers(4);

        $deleted = false;

        DB::listen(static function () use (&$deleted): void {

            if ($deleted) {
                return;
            }

            $deleted = true;

            User::query()->delete();
        });

        $paginator = $this->paginate(2, 2);

        self::assertSame(0, User::query()->count());
        self::assertSame(4, $paginator->total());
    }

    /**
     * Test that a container binding of the paginator is honoured.
     *
     * @return void
     */
    public function testContainerBindingOfThePaginatorIsHonoured(): void
    {
        $this->seedUsers(1);

        $bound = null;

        assert($this->app !== null);

        $this->app->bind(LengthAwarePaginator::class, static function (mixed $app, array $parameters) use (&$bound): LengthAwarePaginator {
            return $bound = new LengthAwarePaginator(...$parameters);
        });

        $paginator = $this->paginate(1, 5);

        self::assertSame($bound, $paginator);
    }

    /**
     * Test that a count past the pages the offset cap allows is capped at their
     * rows, with the guard's last page full and final.
     *
     * @return void
     */
    public function testCountPastTheOffsetWindowIsCapped(): void
    {
        Config::set(self::MAX_OFFSET, 4);

        $this->seedUsers(10);

        [$first, $statements] = $this->paginateRecorded(1, 2);

        self::assertStringEndsWith('limit 7) as ' . DB::connection()->getQueryGrammar()->wrap('aggregate_table'), $statements[1]);
        self::assertInstanceOf(CappedLengthAwarePaginator::class, $first);
        self::assertSame(6, $first->total());
        self::assertSame(3, $first->lastPage());

        $last = $this->paginate(3, 2);

        self::assertInstanceOf(CappedLengthAwarePaginator::class, $last);
        self::assertCount(2, $last->items());
        self::assertNull($last->nextPageUrl());
    }

    /**
     * Test that a count that fills the offset window exactly is not capped.
     *
     * @return void
     */
    public function testCountFillingTheOffsetWindowIsExact(): void
    {
        Config::set(self::MAX_OFFSET, 4);

        $this->seedUsers(6);

        $paginator = $this->paginate(1, 2);

        self::assertSame(LengthAwarePaginator::class, $paginator::class);
        self::assertSame(6, $paginator->total());
    }

    /**
     * Test that a disabled offset cap counts every match without a limit.
     *
     * @return void
     */
    public function testDisabledOffsetCapCountsEveryMatch(): void
    {
        Config::set(self::MAX_OFFSET, 0);

        $this->seedUsers(10);

        [$paginator, $statements] = $this->paginateRecorded(1, 2);

        self::assertStringNotContainsString('limit', $statements[1]);
        self::assertSame(LengthAwarePaginator::class, $paginator::class);
        self::assertSame(10, $paginator->total());
    }

    /**
     * Provide queries carrying pagination clauses of their own.
     *
     * @return iterable<string, array{\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed}>
     */
    public static function prePaginatedQueries(): iterable
    {
        yield 'plain' => [
            static fn (BuilderContract $query) => $query->getQuery()
                ->orderByRaw('case when status = ? then 0 else 1 end', ['active'])
                ->limit(1)
                ->offset(8),
        ];

        yield 'union' => [
            static fn (BuilderContract $query) => $query->getQuery()
                ->where('id', '<', 6)
                ->union(User::query()->toBase()->where('id', '>=', 6))
                ->orderBy('name')
                ->limit(1)
                ->offset(8),
        ];
    }

    /**
     * Test that the query's own limit, offset and order do not bound the count.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed  $shape
     * @return void
     */
    #[DataProvider('prePaginatedQueries')]
    public function testQuerysOwnPaginationDoesNotBoundTheCount(\Closure $shape): void
    {
        Config::set(self::MAX_OFFSET, 0);

        $this->seedUsers(10);

        $paginator  = null;
        $statements = $this->recordStatements(function () use (&$paginator, $shape): void {
            $paginator = $this->paginate(1, 2, $shape);
        });

        assert($paginator instanceof LengthAwarePaginator);

        self::assertStringNotContainsString('order by', $statements[1]);
        self::assertStringNotContainsString('offset', $statements[1]);
        self::assertCount(2, $paginator->items());
        self::assertSame(10, $paginator->total());
    }

    /**
     * Provide queries whose pages the count must agree with, and the rows they
     * match.
     *
     * @return iterable<string, array{\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed, int}>
     */
    public static function shapedQueries(): iterable
    {
        yield 'grouped' => [static fn (BuilderContract $query) => $query->getQuery()->select('name')->groupBy('name'), 6];
        yield 'grouped join without a projection' => [self::groupedJoin(...), 8];

        yield 'union' => [
            static fn (BuilderContract $query) => $query->getQuery()
                ->where('status', 'active')
                ->union(User::query()->toBase()->where('status', 'inactive')),
            7,
        ];

        yield 'array-distinct union' => [
            static fn (BuilderContract $query) => $query->getQuery()
                ->distinct(['name'])
                ->select('name')
                ->where('status', 'active')
                ->union(User::query()->toBase()->select('name')->where('status', 'banned')),
            5,
        ];

        yield 'distinct over a one-to-many join' => [
            static fn (BuilderContract $query) => $query->getQuery()->distinct()->select('users.*')->join('posts', 'posts.user_id', '=', 'users.id'),
            5,
        ];

        yield 'distinct names over a one-to-many join' => [
            static fn (BuilderContract $query) => $query->getQuery()->distinct()->select('users.name')->join('posts', 'posts.user_id', '=', 'users.id'),
            4,
        ];

        yield 'withCount' => [static fn (BuilderContract $query) => $query->withCount('posts'), 9];
    }

    /**
     * Test that shaped queries count the rows their pages show, the counted
     * first page agreeing with the last.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed  $shape
     * @param  int  $rows
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('shapedQueries')]
    public function testShapedQueriesCountTheRowsTheirPagesShow(\Closure $shape, int $rows): void
    {
        $this->assertPagesAgree($shape, $rows);
    }

    /**
     * Provide queries that filter on a select alias in their having clause, and
     * the engines that resolve one there.
     *
     * @return iterable<string, array{\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed, int, list<string>}>
     */
    public static function havingOnAnAlias(): iterable
    {
        yield 'grouped' => [
            static fn (BuilderContract $query) => $query->getQuery()->select('name')->selectRaw('count(*) as members')->groupBy('name')->having('members', '<', 3),
            5,
            ['sqlite', 'mysql'],
        ];

        yield 'ungrouped withCount' => [
            static fn (BuilderContract $query) => $query->withCount('posts')->getQuery()->having('posts_count', '>', 0),
            5,
            ['mysql'],
        ];
    }

    /**
     * Test that a having clause on a select alias keeps the projection it
     * filters on.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed  $shape
     * @param  int  $rows
     * @param  list<string>  $engines
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('havingOnAnAlias')]
    public function testHavingOnASelectAliasCountsTheRowsItsPagesShow(\Closure $shape, int $rows, array $engines): void
    {
        if (!in_array(DB::connection()->getDriverName(), $engines, true)) {
            self::markTestSkipped('This engine refuses the having clause itself.');
        }

        $this->assertPagesAgree($shape, $rows);
    }

    /**
     * Test that a grouped join without a projection counts over the model's
     * columns, which a derived table can name without duplicates.
     *
     * @return void
     */
    public function testGroupedJoinWithoutAProjectionCountsOverTheModelsColumns(): void
    {
        $this->seedShapes();

        $statements = $this->recordStatements(fn () => $this->paginate(1, 2, self::groupedJoin(...)));

        self::assertStringContainsString('(select ' . DB::connection()->getQueryGrammar()->wrap('users.*') . ' from ', $statements[1]);
    }

    /**
     * Test that a query pinned to the writer counts on the writer too, so the
     * count never reads a replica.
     *
     * @return void
     */
    public function testWriterPinnedQueryCountsOnTheWriter(): void
    {
        // Each in-memory database is its own, so the replica has no table
        Config::set('database.connections.split', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'read'     => ['database' => ':memory:'],
            'write'    => ['database' => ':memory:'],
        ]);

        Schema::connection('split')->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        foreach (range(1, 3) as $index) {
            User::on('split')->create(['name' => 'User ' . $index, 'email' => 'user' . $index . '@example.com']);
        }

        $query = User::on('split');
        $query->getQuery()->useWritePdo();

        $paginator = (new OffsetPaginator)->paginate($query, 1, 2);

        self::assertSame(3, $paginator->total());

        DB::purge('split');
    }

    /**
     * Test that the last page the offset cap allows is accepted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testLastPageTheOffsetCapAllowsIsAccepted(): void
    {
        Config::set(self::MAX_OFFSET, 100);

        $this->expectNotToPerformAssertions();

        (new OffsetPaginator)->guard(11, 10);
    }

    /**
     * Test that a page past the offset cap is refused, naming the last page it
     * allows.
     *
     * @return void
     */
    public function testPagePastTheOffsetCapIsRefused(): void
    {
        Config::set(self::MAX_OFFSET, 100);

        $this->assertRefused(12, 10, 11);
    }

    /**
     * Test that the same page is refused at a larger page size.
     *
     * @return void
     */
    public function testTheSamePageIsRefusedAtALargerPageSize(): void
    {
        Config::set(self::MAX_OFFSET, 100);

        $this->assertRefused(11, 20, 6);
    }

    /**
     * Test that a disabled offset cap accepts any page.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDisabledOffsetCapAcceptsAnyPage(): void
    {
        Config::set(self::MAX_OFFSET, 0);

        $this->expectNotToPerformAssertions();

        (new OffsetPaginator)->guard(100000, 100);
    }

    /**
     * Assert that the page is refused with the offset cap's rejection.
     *
     * @param  int  $page
     * @param  int  $limit
     * @param  int  $lastPage
     * @return void
     */
    private function assertRefused(int $page, int $limit, int $lastPage): void
    {
        try {
            (new OffsetPaginator)->guard($page, $limit);

            self::fail('Expected a rejection for a page past the offset cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'page',
                'pointer'   => '',
                'reason'    => QueryCostLimits::MAX_OFFSET,
                'limit'     => $lastPage,
                'actual'    => $page,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Assert that every page of the shaped query reports the rows they show
     * between them.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed  $shape
     * @param  int  $rows
     * @return void
     *
     * @throws \Throwable
     */
    private function assertPagesAgree(\Closure $shape, int $rows): void
    {
        $this->seedShapes();

        $totals = [];
        $shown  = 0;
        $page   = 1;

        do {
            $paginator = $this->paginateThroughRepository($shape, $page);
            $totals[]  = $paginator->total();
            $shown += count($paginator->items());
        } while ($page++ < $paginator->lastPage());

        self::assertSame($rows, $shown);
        self::assertSame(array_fill(0, (int) ceil($rows / 2), $rows), $totals);
    }

    /**
     * Read a page of two through the repository, which completes the order
     * first.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed  $shape
     * @param  int  $page
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, \Tests\Fixtures\Models\User>
     *
     * @throws \Throwable
     */
    private function paginateThroughRepository(\Closure $shape, int $page): LengthAwarePaginator
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse(new Request(['limit' => '2', 'page' => (string) $page]));

        $paginator = $this->app->make(UserRepository::class)->addScope(static function (BuilderContract $query) use ($shape): void {
            $shape($query);
        })->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $paginator);

        return $paginator;
    }

    /**
     * Read the page of users ordered by key, recording the statements run.
     *
     * @param  int  $page
     * @param  int  $limit
     * @return array{\Illuminate\Pagination\LengthAwarePaginator<int, \Tests\Fixtures\Models\User>, list<string>}
     */
    private function paginateRecorded(int $page, int $limit): array
    {
        $paginator  = null;
        $statements = $this->recordStatements(function () use (&$paginator, $page, $limit): void {
            $paginator = $this->paginate($page, $limit, static fn (BuilderContract $query) => $query->getQuery()->orderBy('id'));
        });

        assert($paginator instanceof LengthAwarePaginator);

        return [$paginator, $statements];
    }

    /**
     * Read the given page of the users query, shaped first when a shape is
     * given.
     *
     * @param  int  $page
     * @param  int  $limit
     * @param  (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): mixed)|null  $shape
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, \Tests\Fixtures\Models\User>
     */
    private function paginate(int $page, int $limit, ?\Closure $shape = null): LengthAwarePaginator
    {
        $query = User::query();

        if ($shape !== null) {
            $shape($query);
        }

        return (new OffsetPaginator)->paginate($query, $page, $limit);
    }

    /**
     * Join each user to its posts, grouped by both keys and not projected.
     *
     * @SuppressWarnings("php:S1144")
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @return void
     */
    private static function groupedJoin(BuilderContract $query): void
    {
        $query->getQuery()->join('posts', 'posts.user_id', '=', 'users.id')->groupBy('users.id', 'posts.id');
    }

    /**
     * Seed the given number of users.
     *
     * @param  int  $count
     * @return void
     */
    private function seedUsers(int $count): void
    {
        foreach (range(1, $count) as $index) {
            User::create(['name' => 'User ' . $index, 'email' => 'user' . $index . '@example.com']);
        }
    }

    /**
     * Seed users sharing names and statuses, some with several posts and some
     * with none.
     *
     * @return void
     */
    private function seedShapes(): void
    {
        $users = [
            ['Anna', 'active', 2],
            ['Anna', 'active', 1],
            ['Anna', 'inactive', 0],
            ['Ben', 'inactive', 1],
            ['Ben', 'banned', 0],
            ['Cara', 'banned', 3],
            ['Dan', 'active', 1],
            ['Evan', 'active', 0],
            ['Fay', 'inactive', 0],
        ];

        foreach ($users as $index => [$name, $status, $posts]) {

            $user = User::create(['name' => $name, 'email' => 'user' . $index . '@example.com', 'status' => $status]);

            for ($post = 0; $post < $posts; $post++) {
                Post::create(['user_id' => $user->id, 'title' => 'Post ' . $post, 'body' => 'Content']);
            }
        }
    }
}
