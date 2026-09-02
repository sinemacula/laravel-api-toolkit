<?php

declare(strict_types = 1);

namespace Tests\Unit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the MySqlNgramSearchDriver.
 *
 * The predicates are compiled against a MySQL grammar the suite never connects
 * to, so the emitted clause and its bindings are asserted here whatever engine
 * the suite is running against; that the engine answers them is proven by the
 * driver-gated integration suite. The index proof is driven from a stubbed
 * catalogue, so every arrangement it has to refuse is exercised without one
 * being created first.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MySqlNgramSearchDriver::class)]
final class MySqlNgramSearchDriverTest extends TestCase
{
    /** @var string The connection name the predicates are compiled against */
    private const string CONNECTION = 'mysql_grammar';

    /** @var string The term every test searches for */
    private const string TERM = 'smith';

    /** @var array<int, string> The statements the driver read the catalogue with */
    private array $statements = [];

    /**
     * Register a MySQL connection the driver compiles its predicates against.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->statements = [];

        Config::set('database.connections.' . self::CONNECTION, [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'database' => 'unused',
            'username' => 'unused',
            'password' => '',
            'prefix'   => '',
            'charset'  => 'utf8mb4',
        ]);
    }

    /**
     * Test that the driver implements every match strategy, so no declaration
     * is refused for a shape MySQL can serve.
     *
     * @return void
     */
    public function testImplementsEveryMatchStrategy(): void
    {
        self::assertSame(SearchStrategy::cases(), (new MySqlNgramSearchDriver)->supportedStrategies());
    }

    /**
     * Test that the driver claims it can prove index backing, so a declaration
     * it serves is checked against the live schema.
     *
     * @return void
     */
    public function testClaimsItCanProveIndexBacking(): void
    {
        $driver = new MySqlNgramSearchDriver;

        foreach (SearchStrategy::cases() as $strategy) {
            self::assertTrue($driver->canVerifyIndexBacking($strategy, $this->catalogue()));
        }
    }

    /**
     * Test that an equality match is emitted as a plain comparison against the
     * qualified column, which an ordinary index answers.
     *
     * @return void
     */
    public function testEmitsAPlainComparisonForAnEqualityMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::EXACT);

        self::assertSame('select * from `users` where `users`.`name` = ?', $query->toSql());
        self::assertSame([self::TERM], $query->getBindings());
    }

    /**
     * Test that a prefix match is emitted as a trailing-wildcard comparison
     * naming the escape character the term was escaped with.
     *
     * @return void
     */
    public function testEmitsATrailingWildcardComparisonForAPrefixMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::PREFIX);

        self::assertSame('select * from `users` where `users`.`name` like ? escape \'\\\\\'', $query->toSql());
        self::assertSame([self::TERM . '%'], $query->getBindings());
    }

    /**
     * Test that an anywhere-match is emitted as a boolean-mode match binding
     * the term as a quoted phrase, which is the only form the n-gram parser
     * answers as a substring rather than as a bag of character pairs.
     *
     * @return void
     */
    public function testEmitsABooleanModePhraseMatchForAnAnywhereMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::SUBSTRING);

        self::assertSame('select * from `users` where match (`users`.`name`) against (? in boolean mode)', $query->toSql());
        self::assertSame(['"' . self::TERM . '"'], $query->getBindings());
    }

    /**
     * Test that each declared column is matched separately rather than through
     * one match over the whole set, which would need a composite index per
     * declared combination.
     *
     * @return void
     */
    public function testMatchesEachColumnSeparately(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::SUBSTRING);

        self::assertSame(
            'select * from `users` where match (`users`.`name`) against (? in boolean mode) or match (`users`.`email`) against (? in boolean mode)',
            $query->toSql(),
        );
        self::assertSame(['"' . self::TERM . '"', '"' . self::TERM . '"'], $query->getBindings());
    }

    /**
     * Test that a full-text index over the column alone, created with the
     * n-gram parser, proves an anywhere-match.
     *
     * @return void
     */
    public function testAcceptsAnNgramFullTextIndexOverTheColumn(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_name_ngram` (`name`) /*!50100 WITH PARSER `ngram` */',
        );

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection));
    }

    /**
     * Test that the parser is read from the definition of the table carrying
     * the column, since the information schema does not report it.
     *
     * @return void
     */
    public function testReadsTheParserFromTheTableDefinition(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']]);

        (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection);

        self::assertSame(['show create table `users`'], $this->statements);
    }

    /**
     * Test that a full-text index created with the default parser is refused.
     * It indexes whole words, so a term inside a longer word matches nothing -
     * a wrong answer rather than a slow one.
     *
     * @return void
     */
    public function testRefusesAFullTextIndexCreatedWithTheDefaultParser(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_name_ft', 'columns' => ['name'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_name_ft` (`name`)',
        );

        self::assertSame(
            ['Column "name" is declared searchable with the "substring" strategy, which needs a full-text index over that column '
                . 'alone on table "users", created with the ngram parser'],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection),
        );
    }

    /**
     * Test that a full-text index covering more than the matched column is
     * refused, since MySQL resolves a match only against an index whose column
     * list is exactly the matched one.
     *
     * @return void
     */
    public function testRefusesAFullTextIndexCoveringMoreThanTheColumn(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_search_ngram', 'columns' => ['name', 'email'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_search_ngram` (`name`,`email`) /*!50100 WITH PARSER `ngram` */',
        );

        self::assertCount(1, (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection));
    }

    /**
     * Test that an ordinary index does not prove an anywhere-match, whatever it
     * covers.
     *
     * @return void
     */
    public function testRefusesAnAnywhereMatchBackedOnlyByAnOrdinaryIndex(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'BTREE']]);

        self::assertCount(1, (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection));
    }

    /**
     * Test that an ordinary index leading with the column proves a prefix
     * match, which reads it as a leading-literal range.
     *
     * @return void
     */
    public function testAcceptsAnOrdinaryIndexLeadingWithTheColumnForAPrefixMatch(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_status_index', 'columns' => ['name', 'status'], 'type' => 'BTREE']]);

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, 'name', 'users', $connection));
    }

    /**
     * Test that an index carrying the column anywhere but first does not prove
     * a prefix match.
     *
     * @return void
     */
    public function testRefusesAPrefixMatchOnATrailingIndexColumn(): void
    {
        $connection = $this->catalogue([['name' => 'users_status_name_index', 'columns' => ['status', 'name'], 'type' => 'BTREE']]);

        self::assertSame(
            ['Column "name" is declared searchable with the "prefix" strategy, which needs an index leading with that column on table "users"'],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, 'name', 'users', $connection),
        );
    }

    /**
     * Apply the term to a fresh query compiled against the MySQL grammar.
     *
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function apply(array $columns, SearchStrategy $strategy): Builder
    {
        $query = User::on(self::CONNECTION);

        (new MySqlNgramSearchDriver)->apply($query, $columns, SearchTerm::from(self::TERM), $strategy);

        return $query;
    }

    /**
     * Build a connection reporting the given indexes and table definition.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @param  string  $definition
     * @return \Illuminate\Database\Connection
     */
    private function catalogue(array $indexes = [], string $definition = ''): Connection
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('getQueryGrammar')->willReturn(new MySqlGrammar($connection));
        $connection->method('selectOne')->willReturnCallback(function (string $statement) use ($definition): object {

            $this->statements[] = $statement;

            return (object) ['Create Table' => $definition];
        });

        return $connection;
    }
}
