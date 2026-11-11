<?php

declare(strict_types = 1);

namespace Tests\Unit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Concerns\AssertsBoundPlaceholders;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the MySqlNgramSearchDriver against an unconnected MySQL grammar.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MySqlNgramSearchDriver::class)]
final class MySqlNgramSearchDriverTest extends TestCase
{
    use AssertsBoundPlaceholders;

    /** @var string The connection name the predicates are compiled against */
    private const string CONNECTION = 'mysql_grammar';

    /** @var string The term every test searches for */
    private const string TERM = 'smith';

    /** @var string The statement the driver reads the parser out of */
    private const string DEFINITION_STATEMENT = 'show create table `users`';

    /** @var string The statement the driver reads the token size out of */
    private const string TOKEN_SIZE_STATEMENT = 'select @@ngram_token_size as size';

    /** @var string The whole defect reported when no index matches the declared column list */
    private const string MISSING_INDEX = 'The columns declared with the "substring" strategy ("name") are matched together, '
        . 'so table "users" needs one full-text index over exactly that column list, created with the ngram parser';

    /** @var array<int, string> The statements the driver read the catalogue with */
    private array $statements = [];

    /** @var array<int, string> The reads the driver made of the information schema */
    private array $reads = [];

    /** @var array<int, array<int, mixed>> The bindings each of those reads carried */
    private array $bindings = [];

    /** @var array<int, bool> Whether each statement read the catalogue from a read replica */
    private array $readPdo = [];

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
        $this->reads      = [];
        $this->bindings   = [];
        $this->readPdo    = [];

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
     * Test that the driver implements every match strategy.
     *
     * @return void
     */
    public function testImplementsEveryMatchStrategy(): void
    {
        self::assertSame(SearchStrategy::cases(), (new MySqlNgramSearchDriver)->supportedStrategies());
    }

    /**
     * Test that the driver claims it can prove index backing.
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
     * Test that an anywhere-match declared on its own is accepted.
     *
     * @return void
     */
    public function testAcceptsAnAnywhereMatchDeclaredOnItsOwn(): void
    {
        self::assertNull((new MySqlNgramSearchDriver)->combinationDefect([SearchStrategy::SUBSTRING]));
    }

    /**
     * Test that the strategies an ordinary index serves are accepted together.
     *
     * The engine merges their index reads for the OR instead of scanning.
     *
     * @return void
     */
    public function testAcceptsTheOrdinaryIndexStrategiesTogether(): void
    {
        self::assertNull((new MySqlNgramSearchDriver)->combinationDefect([SearchStrategy::PREFIX, SearchStrategy::EXACT]));
    }

    /**
     * Test that an anywhere-match declared beside another strategy is refused.
     *
     * @return void
     */
    public function testRefusesAnAnywhereMatchDeclaredBesideAnotherStrategy(): void
    {
        self::assertSame(
            'the "substring" strategy is declared alongside another strategy, and a full-text match OR-ed with any other '
                . 'predicate loses the full-text access path and reads the whole table',
            (new MySqlNgramSearchDriver)->combinationDefect([SearchStrategy::SUBSTRING, SearchStrategy::PREFIX]),
        );
    }

    /**
     * Test that an equality match is a plain comparison on the column.
     *
     * @return void
     */
    public function testEmitsAPlainComparisonForAnEqualityMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::EXACT);

        self::assertSame('select * from `users` where `users`.`name` = ?', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a prefix match is a trailing-wildcard comparison.
     *
     * @return void
     */
    public function testEmitsATrailingWildcardComparisonForAPrefixMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::PREFIX);

        self::assertSame('select * from `users` where `users`.`name` like ? escape \'!\'', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that every prefix column contributes its own comparison.
     *
     * @return void
     */
    public function testEmitsOneComparisonPerPrefixColumn(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::PREFIX);

        self::assertSame(
            'select * from `users` where `users`.`name` like ? escape \'!\' or `users`.`email` like ? escape \'!\'',
            $query->toSql(), // @phpstan-ignore staticMethod.dynamicCall
        );
        self::assertSame([self::TERM . '%', self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that every binding keeps a placeholder of its own.
     *
     * A quoted literal left open would swallow the placeholder beside it.
     *
     * @return void
     */
    public function testEveryBindingKeepsAPlaceholderOfItsOwn(): void
    {
        foreach (SearchStrategy::cases() as $strategy) {

            $query = $this->apply(['name', 'email'], $strategy);

            $sql      = $query->toSql(); // @phpstan-ignore staticMethod.dynamicCall
            $bindings = $query->getBindings(); // @phpstan-ignore staticMethod.dynamicCall

            self::assertPlaceholderPerBinding($sql, $bindings);
        }
    }

    /**
     * Test that an anywhere-match is a boolean-mode match on a quoted phrase.
     *
     * Only a phrase makes the n-gram parser match a substring, not loose pairs.
     *
     * @return void
     */
    public function testEmitsABooleanModePhraseMatchForAnAnywhereMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::SUBSTRING);

        self::assertSame('select * from `users` where match (`users`.`name`) against (? in boolean mode)', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame(['"' . self::TERM . '"'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that every declared column is matched through one match.
     *
     * Two matches OR-ed together lose the full-text access path.
     *
     * @return void
     */
    public function testMatchesEveryColumnThroughOneMatch(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::SUBSTRING);

        self::assertSame(
            'select * from `users` where match (`users`.`name`, `users`.`email`) against (? in boolean mode)',
            $query->toSql(), // @phpstan-ignore staticMethod.dynamicCall
        );
        self::assertSame(['"' . self::TERM . '"'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that an n-gram full-text index over the declared columns proves an
     * anywhere-match.
     *
     * @return void
     */
    public function testAcceptsAnNgramFullTextIndexOverTheDeclaredColumns(): void
    {
        $connection = $this->catalogue(
            [
                ['name' => 'PRIMARY', 'columns' => ['id'], 'type' => 'BTREE'],
                ['name' => 'users_email_unique', 'columns' => ['email'], 'type' => 'BTREE'],
                ['name' => 'users_search_ngram', 'columns' => ['name', 'email'], 'type' => 'FULLTEXT'],
            ],
            '  FULLTEXT KEY `users_search_ngram` (`name`,`email`) /*!50100 WITH PARSER `ngram` */',
        );

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection));
    }

    /**
     * Test that the index is matched by its column set, not their order.
     *
     * @return void
     */
    public function testAcceptsTheIndexWhateverOrderItsColumnsAreDeclaredIn(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_search_ngram', 'columns' => ['email', 'name'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_search_ngram` (`email`,`name`) /*!50100 WITH PARSER `ngram` */',
        );

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection));
    }

    /**
     * Test that the token size and the table definition are read in turn.
     *
     * The information schema reports neither the parser nor the token size.
     *
     * @return void
     */
    public function testReadsTheTokenSizeThenTheTableDefinition(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']]);

        (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection);

        self::assertSame([self::TOKEN_SIZE_STATEMENT, self::DEFINITION_STATEMENT], $this->statements);
    }

    /**
     * Test that the token size and table definition are read from the primary.
     *
     * The index states are read there too, so both describe one server.
     *
     * @return void
     */
    public function testReadsTheTokenSizeAndTableDefinitionFromThePrimary(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']]);

        (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection);

        self::assertSame([false, false], $this->readPdo);
    }

    /**
     * Test that a full-text index created with the default parser is refused.
     *
     * It indexes whole words, so a term inside a longer word matches nothing.
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
            ['name' => [self::MISSING_INDEX]],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that a full-text index covering more than the declared columns is
     * refused.
     *
     * MySQL only uses an index whose column list is exactly the matched one.
     *
     * @return void
     */
    public function testRefusesAFullTextIndexCoveringMoreThanTheDeclaredColumns(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_search_ngram', 'columns' => ['name', 'email'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_search_ngram` (`name`,`email`) /*!50100 WITH PARSER `ngram` */',
        );

        self::assertSame(
            ['name' => [self::MISSING_INDEX]],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that an index over part of the declared columns proves none of them.
     *
     * @return void
     */
    public function testRefusesAnIndexCoveringOnlyPartOfTheDeclaredColumns(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_name_ngram` (`name`) /*!50100 WITH PARSER `ngram` */',
        );

        $defect = 'The columns declared with the "substring" strategy ("name", "email") are matched together, '
            . 'so table "users" needs one full-text index over exactly that column list, created with the ngram parser';

        self::assertSame(
            ['name' => [$defect], 'email' => [$defect]],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection),
        );
    }

    /**
     * Test that an ordinary index does not prove an anywhere-match.
     *
     * @return void
     */
    public function testRefusesAnAnywhereMatchBackedOnlyByAnOrdinaryIndex(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'BTREE']]);

        self::assertSame(
            ['name' => [self::MISSING_INDEX]],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that a token size longer than the minimum word length is reported.
     *
     * @return void
     */
    public function testReportsATokenSizeLongerThanTheShortestAcceptedWord(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_name_ngram` (`name`) /*!50100 WITH PARSER `ngram` */',
            4,
        );

        self::assertSame(
            ['name' => ['The connection parses n-grams 4 characters at a time, which is longer than the shortest word a search '
                . 'term may carry (3), so an accepted term would produce no tokens and match nothing']],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that a token size equal to the minimum word length is accepted.
     *
     * @return void
     */
    public function testAcceptsATokenSizeEqualToTheShortestAcceptedWord(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_name_ngram` (`name`) /*!50100 WITH PARSER `ngram` */',
            3,
        );

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection));
    }

    /**
     * Test that a token size the connection does not report is a defect.
     *
     * @return void
     */
    public function testReportsATokenSizeTheConnectionDoesNotReport(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']],
            '  FULLTEXT KEY `users_name_ngram` (`name`) /*!50100 WITH PARSER `ngram` */',
            null,
        );

        self::assertSame(
            ['name' => ['The connection did not report the number of characters its n-gram parser tokenises at a time, '
                . 'so a term short enough to produce no tokens cannot be ruled out']],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that an ordinary index leading with the column proves a prefix
     * match.
     *
     * @return void
     */
    public function testAcceptsAnOrdinaryIndexLeadingWithTheColumnForAPrefixMatch(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_status_index', 'columns' => ['name', 'status'], 'type' => 'BTREE']]);

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection));
    }

    /**
     * Test that an index keyed on only a prefix of the column still proves an
     * equality or prefix match.
     *
     * @return void
     */
    public function testAcceptsAPrefixKeyedIndexForAnEqualityOrPrefixMatch(): void
    {
        $connection = $this->catalogue(
            [['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'BTREE']],
            unordered: ['users_name_index'],
        );

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection));
        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that the read naming the indexes the planner ignores asks for the
     * hidden ones alone, scoped to the table.
     *
     * @return void
     */
    public function testReadsTheIndexesThePlannerIgnores(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'BTREE']]);

        (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection);

        self::assertSame([
            'select lower(index_name) as name, '
            . 'max(case when is_visible = \'NO\' then 1 else 0 end) as disregarded, '
            . 'max(case when seq_in_index = 1 and column_name is null then 1 else 0 end) as expressed, '
            . 'max(case when seq_in_index = 1 and sub_part is not null then 1 else 0 end) as unordered '
            . 'from information_schema.statistics '
                . 'where table_schema = coalesce(?, schema()) and table_name = ? '
                . 'group by lower(index_name)',
        ], $this->reads);
        self::assertSame([[null, 'users']], $this->bindings);
    }

    /**
     * Test that the read is scoped to the prefixed table and its schema.
     *
     * @return void
     */
    public function testScopesTheReadToThePrefixedTableAndItsSchema(): void
    {
        $connection = $this->catalogue(
            [['name' => 'shop_users_name_index', 'columns' => ['name'], 'type' => 'BTREE']],
            prefix: 'shop_',
        );

        (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'reporting.users', $connection);

        self::assertSame([['reporting', 'shop_users']], $this->bindings);
    }

    /**
     * Test that an invisible index proves nothing.
     *
     * The catalogue reports an invisible index exactly as a usable one.
     *
     * @return void
     */
    public function testRefusesAPrefixMatchBackedOnlyByAnInvisibleIndex(): void
    {
        $indexes = [['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'BTREE']];

        $visible = $this->catalogue($indexes);

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $visible));

        // Two names prove the read collects more than its first row
        $invisible = $this->catalogue($indexes, invisible: ['users_email_index', 'users_name_index']);

        self::assertNotSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $invisible));
    }

    /**
     * Test that an anywhere match is refused when the only full-text index over
     * the declared columns is invisible.
     *
     * @return void
     */
    public function testRefusesAnAnywhereMatchBackedOnlyByAnInvisibleIndex(): void
    {
        $indexes    = [['name' => 'users_search_ngram', 'columns' => ['name'], 'type' => 'FULLTEXT']];
        $definition = '  FULLTEXT KEY `users_search_ngram` (`name`) /*!50100 WITH PARSER `ngram` */';

        $visible = $this->catalogue($indexes, $definition);

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $visible));

        $invisible = $this->catalogue($indexes, $definition, invisible: ['users_search_ngram']);

        self::assertNotSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $invisible));
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
            ['name' => ['Column "name" is declared searchable with the "prefix" strategy, which needs an index leading with that column on table "users"']],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that an engine which cannot report index states leaves the proof as
     * it was.
     *
     * Not every server offers the visibility column, and failing there would
     * refuse every search.
     *
     * @return void
     */
    public function testAnEngineThatCannotAnswerLeavesTheProofUnchanged(): void
    {
        $connection = $this->refusingCatalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection));
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
     * Build a connection reporting the given catalogue state.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @param  string  $definition
     * @param  int|null  $tokenSize
     * @param  array<int, string>  $invisible
     * @param  string  $prefix
     * @param  array<int, string>  $unordered
     * @return \Illuminate\Database\Connection
     */
    private function catalogue(array $indexes = [], string $definition = '', ?int $tokenSize = 2, array $invisible = [], string $prefix = '', array $unordered = []): Connection
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getTablePrefix')->willReturn($prefix);
        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('getDriverName')->willReturn('mysql');
        $connection->method('getQueryGrammar')->willReturn(new MySqlGrammar($connection));
        $connection->method('selectFromWriteConnection')->willReturnCallback(function (string $query, array $bindings = []) use ($invisible, $unordered): array {

            $this->reads[]    = $query;
            $this->bindings[] = $bindings;

            return [
                ...array_map(
                    static fn (string $name): object => (object) ['name' => $name, 'disregarded' => 1, 'expressed' => 0, 'unordered' => 0],
                    $invisible,
                ),
                ...array_map(
                    static fn (string $name): object => (object) ['name' => $name, 'disregarded' => 0, 'expressed' => 0, 'unordered' => 1],
                    $unordered,
                ),
            ];
        });
        $connection->method('selectOne')->willReturnCallback(function (string $statement, array $bindings = [], bool $useReadPdo = true) use ($definition, $tokenSize): object {

            $this->statements[] = $statement;
            $this->readPdo[]    = $useReadPdo;

            return $statement === self::TOKEN_SIZE_STATEMENT
                ? (object) ['size' => $tokenSize]
                : (object) ['Create Table' => $definition];
        });

        return $connection;
    }

    /**
     * Build a connection whose read of the index states fails.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @return \Illuminate\Database\Connection
     */
    private function refusingCatalogue(array $indexes = []): Connection
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('getQueryGrammar')->willReturn(new MySqlGrammar($connection));
        $connection->method('selectFromWriteConnection')->willThrowException(
            new QueryException('mysql', 'select', [], new \RuntimeException('Unknown column')),
        );

        return $connection;
    }
}
