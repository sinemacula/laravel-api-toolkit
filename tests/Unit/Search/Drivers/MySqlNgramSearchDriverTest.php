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
 * the suite is running against; that the engine answers them from an index is
 * proven by the driver-gated integration suite. The index proof is driven from
 * a stubbed catalogue, so every arrangement it has to refuse is exercised
 * without one being created first.
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

    /** @var string The statement the driver reads the parser out of */
    private const string DEFINITION_STATEMENT = 'show create table `users`';

    /** @var string The statement the driver reads the token size out of */
    private const string TOKEN_SIZE_STATEMENT = 'select @@ngram_token_size as size';

    /** @var string The whole defect reported when no index matches the declared column list */
    private const string MISSING_INDEX = 'The columns declared with the "substring" strategy ("name") are matched together, '
        . 'so table "users" needs one full-text index over exactly that column list, created with the ngram parser';

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
     * Test that an anywhere-match declared on its own is accepted, since one
     * match over the declared columns keeps the full-text access path.
     *
     * @return void
     */
    public function testAcceptsAnAnywhereMatchDeclaredOnItsOwn(): void
    {
        self::assertNull((new MySqlNgramSearchDriver)->combinationDefect([SearchStrategy::SUBSTRING]));
    }

    /**
     * Test that the strategies an ordinary index serves are accepted together,
     * since the engine combines their bitmaps rather than losing the index to
     * the disjunction.
     *
     * @return void
     */
    public function testAcceptsTheOrdinaryIndexStrategiesTogether(): void
    {
        self::assertNull((new MySqlNgramSearchDriver)->combinationDefect([SearchStrategy::PREFIX, SearchStrategy::EXACT]));
    }

    /**
     * Test that an anywhere-match declared beside another strategy is refused
     * with the whole reason, since a full-text match OR-ed with any other
     * predicate reads the whole table.
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
     * Test that every prefix column contributes its own comparison, which the
     * engine reads as a range on each index and combines.
     *
     * @return void
     */
    public function testEmitsOneComparisonPerPrefixColumn(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::PREFIX);

        self::assertSame(
            'select * from `users` where `users`.`name` like ? escape \'\\\\\' or `users`.`email` like ? escape \'\\\\\'',
            $query->toSql(),
        );
        self::assertSame([self::TERM . '%', self::TERM . '%'], $query->getBindings());
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
     * Test that every declared column is matched through one match over the
     * whole set rather than through a match each, since two matches OR-ed
     * together lose the access path the index exists to provide.
     *
     * @return void
     */
    public function testMatchesEveryColumnThroughOneMatch(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::SUBSTRING);

        self::assertSame(
            'select * from `users` where match (`users`.`name`, `users`.`email`) against (? in boolean mode)',
            $query->toSql(),
        );
        self::assertSame(['"' . self::TERM . '"'], $query->getBindings());
    }

    /**
     * Test that a full-text index over the declared column list, created with
     * the n-gram parser, proves an anywhere-match, and that it is found among
     * the several indexes a real table carries.
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
     * Test that the index is matched by the set of columns it covers rather
     * than by their sequence, since the engine resolves a match the same way
     * whichever order the match names them in.
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
     * Test that the parser and the token size are read from the connection,
     * since neither is reported by the information schema.
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
            ['name' => [self::MISSING_INDEX]],
            (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that a full-text index covering more than the declared columns is
     * refused, since MySQL resolves a match only against an index whose column
     * list is exactly the matched one.
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
     * Test that a full-text index over one of the declared columns does not
     * prove the set, and that the defect is reported against every column in
     * it, since the whole set is matched as a unit.
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
     * Test that an ordinary index does not prove an anywhere-match, whatever it
     * covers.
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
     * Test that a server tokenising n-grams longer than the shortest word a
     * term may carry is reported, since an accepted term would produce no
     * tokens and come back as an empty result indistinguishable from a genuine
     * no-match.
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
     * Test that a token size equal to the shortest accepted word is accepted,
     * so the bound is exercised at exactly the limit as well as one beyond it.
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
     * Test that a connection reporting no token size is reported rather than
     * read as a token size small enough, since the proof was not obtained.
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
     * match, which reads it as a leading-literal range.
     *
     * @return void
     */
    public function testAcceptsAnOrdinaryIndexLeadingWithTheColumnForAPrefixMatch(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_status_index', 'columns' => ['name', 'status'], 'type' => 'BTREE']]);

        self::assertSame([], (new MySqlNgramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection));
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
     * Build a connection reporting the given indexes, table definition, and
     * n-gram token size.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @param  string  $definition
     * @param  int|null  $tokenSize
     * @return \Illuminate\Database\Connection
     */
    private function catalogue(array $indexes = [], string $definition = '', ?int $tokenSize = 2): Connection
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('getQueryGrammar')->willReturn(new MySqlGrammar($connection));
        $connection->method('selectOne')->willReturnCallback(function (string $statement) use ($definition, $tokenSize): object {

            $this->statements[] = $statement;

            return $statement === self::TOKEN_SIZE_STATEMENT
                ? (object) ['size' => $tokenSize]
                : (object) ['Create Table' => $definition];
        });

        return $connection;
    }
}
