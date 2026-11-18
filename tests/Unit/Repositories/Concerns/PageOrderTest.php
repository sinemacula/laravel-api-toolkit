<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Repositories\Concerns\PageOrder;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the PageOrder concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PageOrder::class)]
final class PageOrderTest extends TestCase
{
    /** @var string */
    private const string KEY = 'users.id';

    /** @var string */
    private const string MISMATCH = 'The cursor does not match the requested order; restart from the first page.';

    /** @var string */
    private const string MALFORMED = 'The cursor could not be read; restart from the first page.';

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\PageOrder */
    private PageOrder $pageOrder;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $this->pageOrder = new PageOrder(new ColumnLiteral($this->app->make(SchemaIntrospectionProvider::class)));
    }

    /**
     * Test that the qualified key is appended after the requested order.
     *
     * @return void
     */
    public function testAppendsTheQualifiedKeyAfterTheRequestedOrder(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('name')));

        self::assertSame([['name', 'asc'], [self::KEY, 'asc']], $this->orders($query));
    }

    /**
     * Test that the appended key follows the last order's direction.
     *
     * @return void
     */
    public function testAppendedKeyFollowsTheLastDirection(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('name')->orderBy('email', 'desc')));

        self::assertSame([['name', 'asc'], ['email', 'desc'], [self::KEY, 'desc']], $this->orders($query));
    }

    /**
     * Test that the last direction wins over an earlier one.
     *
     * @return void
     */
    public function testAppendedKeyFollowsTheLastDirectionOverAnEarlierOne(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('name', 'desc')->orderBy('email')));

        self::assertSame([['name', 'desc'], ['email', 'asc'], [self::KEY, 'asc']], $this->orders($query));
    }

    /**
     * Test that an unordered query is ordered by the key ascending.
     *
     * @return void
     */
    public function testUnorderedQueryIsOrderedByTheKeyAscending(): void
    {
        $query = $this->pageOrder->complete(User::query());

        self::assertSame([[self::KEY, 'asc']], $this->orders($query));
    }

    /**
     * Provide orders that already end on the model key.
     *
     * @return iterable<string, array{string}>
     */
    public static function keyOrderProvider(): iterable
    {
        yield 'bare key' => ['id'];
        yield 'qualified key' => [self::KEY];
    }

    /**
     * Test that a query already ordered by the key is left as it is.
     *
     * @param  string  $key
     * @return void
     */
    #[DataProvider('keyOrderProvider')]
    public function testQueryOrderedByTheKeyIsLeftAlone(string $key): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy($key, 'desc')->orderBy('name')));

        self::assertSame([[$key, 'desc'], ['name', 'asc']], $this->orders($query));
    }

    /**
     * Test that a raw order neither blocks the key nor sets its direction.
     *
     * @return void
     */
    public function testRawOrderDoesNotSetTheDirection(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('name', 'desc')->inRandomOrder()));

        self::assertSame([['name', 'desc'], [null, null], [self::KEY, 'desc']], $this->orders($query));
    }

    /**
     * Test that a query ordered only at random is ordered by the key ascending.
     *
     * @return void
     */
    public function testRandomOnlyOrderTakesTheKeyAscending(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->inRandomOrder()));

        self::assertSame([[null, null], [self::KEY, 'asc']], $this->orders($query));
    }

    /**
     * Test that an expression order is not mistaken for the key.
     *
     * @return void
     */
    public function testExpressionOrderIsNotMistakenForTheKey(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy(new Expression('id'), 'desc')));

        self::assertSame([self::KEY, 'desc'], $this->orders($query)[1]);
    }

    /**
     * Test that a union query is left unordered.
     *
     * @return void
     */
    public function testUnionQueryIsSkipped(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->union(User::query()->getQuery())));

        self::assertSame([], $this->orders($query));
        self::assertEmpty($query->getQuery()->unionOrders);
    }

    /**
     * Test that a grouped query takes its unordered plain group columns.
     *
     * @return void
     */
    public function testGroupedQueryTakesItsUnorderedGroupColumns(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->select(['status', 'name'])->groupBy('status', 'name', 'email')->orderBy('name', 'desc'));

        self::assertSame(
            [['name', 'desc'], ['status', 'desc'], ['email', 'desc']],
            $this->orders($this->pageOrder->complete($query)),
        );
    }

    /**
     * Test that a bare group column ordered by its qualified name is not
     * appended again.
     *
     * @return void
     */
    public function testBareGroupColumnOrderedByItsQualifiedNameIsNotAppendedAgain(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->select('status')->groupBy('status')->orderBy('users.status'));

        self::assertSame([['users.status', 'asc']], $this->orders($this->pageOrder->complete($query)));
    }

    /**
     * Test that a qualified group column is appended under a bare order of the
     * same name, which may be a select alias.
     *
     * @return void
     */
    public function testQualifiedGroupColumnIsAppendedUnderABareOrderOfTheSameName(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->selectRaw('lower(users.name) as name')->groupBy('users.name')->orderBy('name'));

        self::assertSame([['name', 'asc'], ['users.name', 'asc']], $this->orders($this->pageOrder->complete($query)));
    }

    /**
     * Test that a distinct grouped query takes the group columns it selects.
     *
     * @return void
     */
    public function testDistinctGroupedQueryTakesTheGroupColumnsItSelects(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->distinct()->select(['status', 'users.name'])->groupBy('status', 'name'));

        self::assertSame([['status', 'asc'], ['name', 'asc']], $this->orders($this->pageOrder->complete($query)));
    }

    /**
     * Test that a distinct grouped query takes a joined group column its
     * table's wildcard selects.
     *
     * @return void
     */
    public function testDistinctGroupedQueryTakesAJoinedColumnItsWildcardSelects(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->distinct()->select(['roles.*'])->groupBy('roles.name'));

        self::assertSame([['roles.name', 'asc']], $this->orders($this->pageOrder->complete($query)));
    }

    /**
     * Provide grouped or aggregated queries no tiebreaker can be appended to.
     *
     * @return iterable<string, array{\Closure(\Illuminate\Database\Query\Builder): mixed}>
     */
    public static function untiedQueryProvider(): iterable
    {
        yield 'expression group' => [static fn (QueryBuilder $query) => $query->groupByRaw('lower(name)')];
        yield 'plain group before an expression group' => [static fn (QueryBuilder $query) => $query->groupBy('status')->groupByRaw('lower(name)')];
        yield 'having without groups' => [static fn (QueryBuilder $query) => $query->havingRaw('count(*) > 0')];
        yield 'distinct without the key' => [static fn (QueryBuilder $query) => $query->distinct()->select(['name'])];
        yield 'distinct over an expression' => [static fn (QueryBuilder $query) => $query->distinct()->select([new Expression('id')])];
        yield 'distinct on columns' => [static fn (QueryBuilder $query) => $query->distinct(['name'])->select(['id', 'name'])];
        yield 'distinct without a group column' => [static fn (QueryBuilder $query) => $query->distinct()->select(['name'])->groupBy('name', 'id')];
        yield 'distinct without a joined group column' => [static fn (QueryBuilder $query) => $query->distinct()->select(['name'])->groupBy('roles.name')];
        yield 'distinct selecting another table' => [static fn (QueryBuilder $query) => $query->distinct()->select(['users.*'])->groupBy('roles.name')];
    }

    /**
     * Test that a query with no safe tiebreaker is left as it is.
     *
     * @param  \Closure(\Illuminate\Database\Query\Builder): mixed  $shape
     * @return void
     */
    #[DataProvider('untiedQueryProvider')]
    public function testQueryWithoutASafeTiebreakerIsLeftAlone(\Closure $shape): void
    {
        self::assertSame([], $this->orders($this->pageOrder->complete($this->users($shape))));
    }

    /**
     * Provide projections that carry the model key.
     *
     * @return iterable<string, array{array<int, string>}>
     */
    public static function keyProjectionProvider(): iterable
    {
        yield 'bare key' => [['name', 'id']];
        yield 'qualified key' => [['name', self::KEY]];
        yield 'table wildcard' => [['users.*']];
        yield 'wildcard' => [['*']];
    }

    /**
     * Test that a distinct query projecting the key takes the key.
     *
     * @param  array<int, string>  $columns
     * @return void
     */
    #[DataProvider('keyProjectionProvider')]
    public function testDistinctQueryProjectingTheKeyTakesTheKey(array $columns): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->distinct()->select($columns)));

        self::assertSame([[self::KEY, 'asc']], $this->orders($query));
    }

    /**
     * Test that a distinct query with the default projection takes the key.
     *
     * @return void
     */
    public function testDistinctQueryWithTheDefaultProjectionTakesTheKey(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->distinct()));

        self::assertSame([[self::KEY, 'asc']], $this->orders($query));
    }

    /**
     * Test that a narrowed projection without distinct still takes the key.
     *
     * @return void
     */
    public function testNarrowedProjectionWithoutDistinctTakesTheKey(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->select(['name'])));

        self::assertSame([[self::KEY, 'asc']], $this->orders($query));
    }

    /**
     * Test that an empty token reads as the first page.
     *
     * @return void
     */
    public function testEmptyTokenIsTheFirstPage(): void
    {
        self::assertNull($this->pageOrder->cursor($this->users(static fn (QueryBuilder $query) => $query->orderBy('name')), ''));
    }

    /**
     * Test that a valid token is returned decoded.
     *
     * @return void
     */
    public function testValidTokenIsReturnedDecoded(): void
    {
        $query  = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('name')));
        $cursor = $this->pageOrder->cursor($query, (new Cursor(['name' => 'Ann', self::KEY => 3], false))->encode());

        self::assertInstanceOf(Cursor::class, $cursor);
        self::assertSame(['name' => 'Ann', self::KEY => 3, '_pointsToNextItems' => false], $cursor->toArray());
    }

    /**
     * Provide tokens that do not decode into a cursor.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedTokenProvider(): iterable
    {
        yield 'not base64 json' => ['garbage'];
        yield 'json without a direction flag' => [self::encode([self::KEY => 1])];
        yield 'non-bool direction flag' => [self::encode([self::KEY => 1, '_pointsToNextItems' => 'yes'])];
        yield 'numeric direction flag' => [self::encode([self::KEY => 1, '_pointsToNextItems' => 1])];
        yield 'json scalar' => [self::encode(true)];
    }

    /**
     * Test that a token that does not decode is rejected.
     *
     * @param  string  $token
     * @return void
     */
    #[DataProvider('malformedTokenProvider')]
    public function testMalformedTokenIsRejected(string $token): void
    {
        $this->assertCursorRejected(fn () => $this->pageOrder->cursor(User::query(), $token), self::MALFORMED);
    }

    /**
     * Provide cursor parameters that cannot seek the ordered query.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function mismatchedParameterProvider(): iterable
    {
        yield 'missing ordered column' => [[self::KEY => 1]];
        yield 'missing key' => [['name' => 'Ann']];
        yield 'column the order does not name' => [['name' => 'Ann', self::KEY => 1, 'email' => 'ann@example.com']];
        yield 'null ordered column' => [['name' => null, self::KEY => 1]];
        yield 'array ordered column' => [['name' => ['Ann'], self::KEY => 1]];
        yield 'word on the integer key' => [['name' => 'Ann', self::KEY => 'abc']];
        yield 'NUL in the ordered column' => [['name' => "A\0n", self::KEY => 1]];
    }

    /**
     * Test that a token not matching the query's order is rejected.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     */
    #[DataProvider('mismatchedParameterProvider')]
    public function testTokenNotMatchingTheOrderIsRejected(array $parameters): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('name')));
        $token = (new Cursor($parameters))->encode();

        $this->assertCursorRejected(fn () => $this->pageOrder->cursor($query, $token), self::MISMATCH);
    }

    /**
     * Test that a boundary on an unqualified column is checked against that
     * column's type.
     *
     * @return void
     */
    public function testUnqualifiedBoundaryIsCheckedAgainstItsColumnType(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('created_at')));

        self::assertInstanceOf(Cursor::class, $this->pageOrder->cursor($query, (new Cursor(['created_at' => '2024-01-01 10:00:00', self::KEY => 1]))->encode()));

        $this->assertCursorRejected(fn () => $this->pageOrder->cursor($query, (new Cursor(['created_at' => 'not-a-date', self::KEY => 1]))->encode()), self::MISMATCH);
    }

    /**
     * Test that a boundary on another table's column is not checked against a
     * column of the model's own.
     *
     * @return void
     */
    public function testBoundaryOnAnotherTablesColumnIsUnchecked(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->orderBy('posts.id')->orderBy(self::KEY));

        self::assertInstanceOf(Cursor::class, $this->pageOrder->cursor($query, (new Cursor(['posts.id' => 'abc', self::KEY => 1]))->encode()));
    }

    /**
     * Test that a date boundary the paginator wrote from a date cast is read as
     * its day, while one naming no day is refused.
     *
     * @return void
     */
    public function testDateBoundaryWrittenFromADateCastIsAccepted(): void
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn(['day' => new ColumnDefinition('day', 'date', false)]);

        $pageOrder = new PageOrder(new ColumnLiteral($introspector));
        $query     = $pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('day')));

        self::assertInstanceOf(Cursor::class, $pageOrder->cursor($query, (new Cursor(['day' => (string) CarbonImmutable::parse('2024-01-01'), self::KEY => 1]))->encode()));

        $this->assertCursorRejected(fn () => $pageOrder->cursor($query, (new Cursor(['day' => '2024-02-30 00:00:00', self::KEY => 1]))->encode()), self::MISMATCH);
    }

    /**
     * Test that raw and expression orders ask nothing of the token.
     *
     * @return void
     */
    public function testRawAndExpressionOrdersAskNothingOfTheToken(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->inRandomOrder()->orderBy(new Expression('lower(name)'))->orderBy(self::KEY));

        self::assertInstanceOf(Cursor::class, $this->pageOrder->cursor($query, (new Cursor([self::KEY => 1]))->encode()));
    }

    /**
     * Test that an unordered query asks the token for the key the paginator
     * orders by.
     *
     * @return void
     */
    public function testUnorderedQueryAsksTheTokenForTheKey(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->union(User::query()->getQuery()));

        self::assertInstanceOf(Cursor::class, $this->pageOrder->cursor($query, (new Cursor([self::KEY => 1]))->encode()));

        $this->assertCursorRejected(fn () => $this->pageOrder->cursor($query, (new Cursor(['id' => 1]))->encode()), self::MISMATCH);
    }

    /**
     * Test that a column the order names twice is asked of the token once.
     *
     * @return void
     */
    public function testColumnOrderedTwiceIsAskedOfTheTokenOnce(): void
    {
        $query = $this->pageOrder->complete($this->users(static fn (QueryBuilder $query) => $query->orderBy('name')->orderBy('name', 'desc')));

        self::assertInstanceOf(Cursor::class, $this->pageOrder->cursor($query, (new Cursor(['name' => 'Ann', self::KEY => 1]))->encode()));
    }

    /**
     * Test that a union's own order is what the token is checked against.
     *
     * @return void
     */
    public function testUnionOrderIsWhatTheTokenIsCheckedAgainst(): void
    {
        $query = $this->users(static fn (QueryBuilder $query) => $query->union(User::query()->getQuery())->orderBy('name'));

        self::assertInstanceOf(Cursor::class, $this->pageOrder->cursor($query, (new Cursor(['name' => 'Ann']))->encode()));
    }

    /**
     * Encode the payload as a cursor token, whatever its shape.
     *
     * @param  mixed  $payload
     * @return string
     */
    private static function encode(mixed $payload): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode((string) json_encode($payload)));
    }

    /**
     * Build a users query shaped by the given callback.
     *
     * @param  \Closure(\Illuminate\Database\Query\Builder): mixed  $shape
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function users(\Closure $shape): Builder
    {
        $query = User::query();

        $shape($query->getQuery());

        return $query;
    }

    /**
     * Read the query's orders as column and direction pairs.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>  $query
     * @return array<int, array{mixed, mixed}>
     */
    private function orders(Builder $query): array
    {
        return array_map(
            static fn (array $order): array => [$order['column'] ?? null, $order['direction'] ?? null],
            $query->getQuery()->orders ?? [],
        );
    }

    /**
     * Assert that reading the cursor rejects it with the given message.
     *
     * @param  callable(): mixed  $read
     * @param  string  $message
     * @return void
     */
    private function assertCursorRejected(callable $read, string $message): void
    {
        try {
            $read();
            self::fail('Expected a ValidationException for the cursor.');
        } catch (ValidationException $exception) {
            self::assertSame(['cursor' => [$message]], $exception->errors());
        }
    }
}
