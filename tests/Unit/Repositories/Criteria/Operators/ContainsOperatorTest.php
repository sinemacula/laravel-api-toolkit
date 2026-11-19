<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Processors\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator;
use Tests\Fixtures\Models\Log;
use Tests\TestCase;

/**
 * Tests for the ContainsOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ContainsOperator::class)]
final class ContainsOperatorTest extends TestCase
{
    /** @var string The qualified JSON column every clause reads */
    private const string COLUMN = 'logs.context';

    /** @var string The SQLite clause matching one element of the document */
    private const string SQLITE_ELEMENT = 'exists (select 1 from json_each("logs"."context") where json_type("logs"."context") <> ? and %s)';

    /** @var string The SQLite predicate matching a string element */
    private const string SQLITE_STRING = '"json_each"."type" = ? and "json_each"."value" = ?';

    /** @var string The MySQL clause matching one element of the document */
    private const string MYSQL_ELEMENT = 'json_contains(`logs`.`context`, ?)';

    /** @var string The PostgreSQL clause matching one element of the document */
    private const string PGSQL_ELEMENT = '("logs"."context")::jsonb @> ?';

    /**
     * Test that the operator accepts JSON elements.
     *
     * @return void
     */
    public function testOperatorAcceptsJsonElements(): void
    {
        self::assertSame(FilterValueShape::ELEMENTS, (new ContainsOperator)->valueShape());
    }

    /**
     * Provide values with the clause and bindings each compiles to on each
     * engine.
     *
     * @return iterable<string, array{class-string<\Illuminate\Database\Query\Grammars\Grammar>, array<int, scalar>|scalar, string, array<int, mixed>}>
     */
    public static function compiledClauseProvider(): iterable
    {
        $string  = sprintf(self::SQLITE_ELEMENT, self::SQLITE_STRING);
        $number  = sprintf(self::SQLITE_ELEMENT, '"json_each"."type" in (?, ?) and json_each.value = cast(? as numeric)');
        $boolean = sprintf(self::SQLITE_ELEMENT, '"json_each"."type" = ?');

        yield 'SQLite string' => [SQLiteGrammar::class, 'php', $string, ['object', 'text', 'php']];
        yield 'SQLite integer' => [SQLiteGrammar::class, 1, $number, ['object', 'integer', 'real', 1]];
        yield 'SQLite float' => [SQLiteGrammar::class, 1.5, $number, ['object', 'integer', 'real', 1.5]];
        yield 'SQLite true' => [SQLiteGrammar::class, true, $boolean, ['object', 'true']];
        yield 'SQLite false' => [SQLiteGrammar::class, false, $boolean, ['object', 'false']];
        yield 'SQLite list' => [SQLiteGrammar::class, ['php', 2], $string . ' and ' . $number, ['object', 'text', 'php', 'object', 'integer', 'real', 2]];
        yield 'SQLite JSON-encoded string' => [SQLiteGrammar::class, '["php"]', $string, ['object', 'text', '["php"]']];
        yield 'MySQL string' => [MySqlGrammar::class, 'php', self::MYSQL_ELEMENT, ['"php"']];
        yield 'MySQL list' => [MySqlGrammar::class, ['php', true], self::MYSQL_ELEMENT . ' and ' . self::MYSQL_ELEMENT, ['"php"', 'true']];
        yield 'PostgreSQL string' => [PostgresGrammar::class, 'php', self::PGSQL_ELEMENT, ['"php"']];
        yield 'PostgreSQL list' => [PostgresGrammar::class, ['php', 2], self::PGSQL_ELEMENT . ' and ' . self::PGSQL_ELEMENT, ['"php"', '2']];
    }

    /**
     * Test that every item compiles to its own clause matching an element of
     * the same JSON type.
     *
     * @param  class-string<\Illuminate\Database\Query\Grammars\Grammar>  $grammar
     * @param  array<int, bool|float|int|string>|bool|float|int|string  $value
     * @param  string  $where
     * @param  array<int, mixed>  $bindings
     * @return void
     */
    #[DataProvider('compiledClauseProvider')]
    public function testEveryItemCompilesToAClauseMatchingAnElementOfItsType(string $grammar, array|bool|float|int|string $value, string $where, array $bindings): void
    {
        $query = $this->logsQuery($grammar);

        (new ContainsOperator)->apply($query, self::COLUMN, $value);

        self::assertStringEndsWith(' where ' . $where, $query->toSql());
        self::assertSame($bindings, $query->getBindings());
    }

    /**
     * Test that a comma-separated string matches any of its trimmed items,
     * grouped apart from an earlier clause.
     *
     * @return void
     */
    public function testCommaSeparatedStringMatchesAnyOfItsItemsInOneGroup(): void
    {
        $query = $this->logsQuery(MySqlGrammar::class);

        $query->where('level', 'info');

        (new ContainsOperator)->apply($query, self::COLUMN, ' php , , rust ');

        self::assertStringEndsWith(' where `level` = ? and (' . self::MYSQL_ELEMENT . ' or ' . self::MYSQL_ELEMENT . ')', $query->toSql());
        self::assertSame(['info', '"php"', '"rust"'], $query->getBindings());
    }

    /**
     * Test that a comma-separated string matches any of its items on SQLite.
     *
     * @return void
     */
    public function testCommaSeparatedStringMatchesAnyOfItsItemsOnSqlite(): void
    {
        $query = $this->logsQuery(SQLiteGrammar::class);

        (new ContainsOperator)->apply($query, self::COLUMN, 'php,rust');

        $string = sprintf(self::SQLITE_ELEMENT, self::SQLITE_STRING);

        self::assertStringEndsWith(' where (' . $string . ' or ' . $string . ')', $query->toSql());
        self::assertSame(['object', 'text', 'php', 'object', 'text', 'rust'], $query->getBindings());
    }

    /**
     * Test that a string of only delimiters adds no clause.
     *
     * @return void
     */
    public function testStringOfOnlyDelimitersAddsNoClause(): void
    {
        $query = $this->logsQuery(MySqlGrammar::class);

        (new ContainsOperator)->apply($query, self::COLUMN, ' , ,');

        self::assertSame([], $query->getQuery()->wheres);
    }

    /**
     * Test that a comma-separated value reports one item per clause.
     *
     * @return void
     */
    public function testCommaSeparatedValueReportsItsExpandedItemCount(): void
    {
        $operator = new ContainsOperator;

        self::assertSame(2, $operator->countValueItems('Alice,Bob'));
        self::assertSame(3, $operator->countValueItems('a,b,c'));
        self::assertSame(2, $operator->countValueItems(' Alice , , Bob '));
        self::assertSame(2, $operator->countValueItems('["a","b"]'));
        self::assertSame(0, $operator->countValueItems(',,'));
    }

    /**
     * Test that a value applied without splitting reports one item.
     *
     * @return void
     */
    public function testValueAppliedWithoutSplittingReportsOneItem(): void
    {
        $operator = new ContainsOperator;

        self::assertSame(1, $operator->countValueItems('Alice'));
        self::assertSame(1, $operator->countValueItems('["a"]'));
        self::assertSame(1, $operator->countValueItems(123));
    }

    /**
     * Build a logs query compiled by the given grammar.
     *
     * @param  class-string<\Illuminate\Database\Query\Grammars\Grammar>  $grammar
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\Log>
     */
    private function logsQuery(string $grammar): Builder
    {
        $connection = self::createStub(Connection::class);

        $connection->method('getTablePrefix')->willReturn('');

        return (new Builder(new QueryBuilder($connection, new $grammar($connection), new Processor)))->setModel(new Log);
    }
}
