<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use Tests\TestCase;

/**
 * Index eligibility inspector tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
final class IndexEligibilityInspectorTest extends TestCase
{
    /** @var array<int, string> The statements the connection was asked for */
    private array $statements = [];

    /** @var array<int, array<int, mixed>> The bindings each statement carried */
    private array $bindings = [];

    /**
     * Test that an engine the inspector cannot question is asked nothing and
     * reports nothing.
     *
     * A reader over such an engine has to be left exactly as strict as it was,
     * rather than refusing every index it cannot get an answer about.
     *
     * @return void
     */
    public function testAnEngineItCannotQuestionIsAskedNothing(): void
    {
        $eligibility = (new IndexEligibilityInspector)->inspect('users', $this->connection('mariadb'));

        self::assertSame([], $this->statements);
        self::assertTrue($eligibility->describes('users_name_index'));
    }

    /**
     * Test that a connection naming no engine at all is asked nothing.
     *
     * @return void
     */
    public function testAConnectionNamingNoEngineIsAskedNothing(): void
    {
        $eligibility = (new IndexEligibilityInspector)->inspect('users', $this->connection(null));

        self::assertSame([], $this->statements);
        self::assertTrue($eligibility->describes('users_name_index'));
    }

    /**
     * Test that an engine answering through pragmas is read for the two facts
     * it holds.
     *
     * It holds no index back from a query, so nothing it reports is ever
     * disregarded, and the read says so rather than leaving the fact unasked.
     * The table is asked for under the name it was created with, which carries
     * the connection's prefix.
     *
     * @return void
     */
    public function testReadsTheFactsAnEngineAnsweringThroughPragmasHolds(): void
    {
        $connection = $this->connection('sqlite', [
            (object) ['name' => 'users_live_index', 'disregarded' => 0, 'restricted' => 1, 'expressed' => 0],
            (object) ['name' => 'users_lower_index', 'disregarded' => 0, 'restricted' => 0, 'expressed' => 1],
        ], 'api_');

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->restricts('users_live_index'));
        self::assertTrue($eligibility->keysAnExpression('users_lower_index'));
        self::assertFalse($eligibility->disregards('users_live_index'));
        self::assertFalse($eligibility->lacksColumnOrder('users_live_index'));
        self::assertFalse($eligibility->lacksColumnOrder('users_lower_index'));

        self::assertSame([
            'select lower(il.name) as name, '
            . '0 as disregarded, '
            . 'il.partial as restricted, '
            . '(select case when ii.name is null then 1 else 0 end '
            . 'from pragma_index_info(il.name, ?) ii where ii.seqno = 0) as expressed '
            . 'from pragma_index_list(?, ?) il',
        ], $this->statements);

        // The prefix belongs to the table as created, not to the model's name.
        self::assertSame([[null, 'api_users', null]], $this->bindings);
    }

    /**
     * Test that an engine keeping index statistics is read for the three facts
     * it can answer.
     *
     * @return void
     */
    public function testReadsTheFactsAnEngineKeepingStatisticsCanAnswer(): void
    {
        $connection = $this->connection('mysql', [
            (object) ['name' => 'users_hidden_index', 'disregarded' => 1, 'expressed' => 0, 'unordered' => 0],
            (object) ['name' => 'users_lower_index', 'disregarded' => 0, 'expressed' => 1, 'unordered' => 0],
            (object) ['name' => 'users_name_index', 'disregarded' => 0, 'expressed' => 0, 'unordered' => 0],
            (object) ['name' => 'users_name_prefix_index', 'disregarded' => 0, 'expressed' => 0, 'unordered' => 1],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->disregards('users_hidden_index'));
        self::assertTrue($eligibility->keysAnExpression('users_lower_index'));
        self::assertTrue($eligibility->describes('users_name_index'));
        self::assertFalse($eligibility->lacksColumnOrder('users_name_index'));
        self::assertTrue($eligibility->lacksColumnOrder('users_name_prefix_index'));
        self::assertTrue($eligibility->describes('users_name_prefix_index'));
        self::assertFalse($eligibility->collatesApart('users_name_prefix_index'));
        self::assertFalse($eligibility->restricts('users_hidden_index'));

        self::assertSame([
            'select lower(index_name) as name, '
            . 'max(case when is_visible = \'NO\' then 1 else 0 end) as disregarded, '
            . 'max(case when seq_in_index = 1 and column_name is null then 1 else 0 end) as expressed, '
            . 'max(case when seq_in_index = 1 and sub_part is not null then 1 else 0 end) as unordered '
            . 'from information_schema.statistics '
            . 'where table_schema = coalesce(?, schema()) and table_name = ? '
            . 'group by lower(index_name)',
        ], $this->statements);
        self::assertSame([[null, 'users']], $this->bindings);
    }

    /**
     * Test that an engine keeping an index catalogue is read for all five
     * facts.
     *
     * @return void
     */
    public function testReadsTheFactsAnEngineKeepingACatalogueCanAnswer(): void
    {
        $connection = $this->connection('pgsql', [
            (object) ['name' => 'users_invalid_index', 'disregarded' => 1, 'restricted' => 0, 'expressed' => 0],
            (object) ['name' => 'users_live_index', 'disregarded' => 0, 'restricted' => 1, 'expressed' => 0],
            (object) ['name' => 'users_lower_index', 'disregarded' => 0, 'restricted' => 0, 'expressed' => 1],
            (object) ['name' => 'users_pattern_index', 'disregarded' => 0, 'restricted' => 0, 'expressed' => 0, 'unordered' => 1, 'recollated' => 0],
            (object) ['name' => 'users_collated_index', 'disregarded' => 0, 'restricted' => 0, 'expressed' => 0, 'unordered' => 0, 'recollated' => 1],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->disregards('users_invalid_index'));
        self::assertTrue($eligibility->restricts('users_live_index'));
        self::assertTrue($eligibility->keysAnExpression('users_lower_index'));
        self::assertFalse($eligibility->lacksColumnOrder('users_lower_index'));
        self::assertTrue($eligibility->lacksColumnOrder('users_pattern_index'));
        self::assertTrue($eligibility->describes('users_pattern_index'));
        self::assertFalse($eligibility->collatesApart('users_pattern_index'));
        self::assertTrue($eligibility->collatesApart('users_collated_index'));
        self::assertTrue($eligibility->lacksColumnOrder('users_collated_index'));
        self::assertFalse($eligibility->describes('users_collated_index'));

        self::assertSame([
            'select lower(ic.relname) as name, '
            . '(not i.indisvalid)::int as disregarded, '
            . '(i.indpred is not null)::int as restricted, '
            . '(i.indkey[0] = 0)::int as expressed, '
            . '(am.amname = \'btree\' and i.indkey[0] <> 0 and '
            . 'not exists (select 1 from pg_opclass d where d.opcdefault and d.opcmethod = oc.opcmethod '
            . 'and d.opcintype = oc.opcintype and d.opcfamily = oc.opcfamily))::int as unordered, '
            . '(i.indkey[0] <> 0 and i.indcollation[0] <> 0 and i.indcollation[0] <> a.attcollation)::int as recollated '
            . 'from pg_index i '
            . 'join pg_class c on c.oid = i.indrelid '
            . 'join pg_namespace n on n.oid = c.relnamespace '
            . 'join pg_class ic on ic.oid = i.indexrelid '
            . 'join pg_am am on am.oid = ic.relam '
            . 'left join pg_opclass oc on oc.oid = i.indclass[0] '
            . 'left join pg_attribute a on a.attrelid = i.indrelid and a.attnum = i.indkey[0] '
            . 'where n.nspname = coalesce(?::text, current_schema()) and c.relname = ?',
        ], $this->statements);
        self::assertSame([[null, 'users']], $this->bindings);
    }

    /**
     * Test that an engine answering through pragmas is asked about the schema
     * the reference names.
     *
     * Neither pragma defaults to the schema a qualified reference names, so a
     * table on an attached database would otherwise be answered for by whatever
     * carries its name on the main one, and the facts would belong to a
     * different table entirely.
     *
     * @return void
     */
    public function testAsksThePragmasAboutTheSchemaTheReferenceNames(): void
    {
        (new IndexEligibilityInspector)->inspect('reporting.users', $this->connection('sqlite'));

        self::assertSame([['reporting', 'users', 'reporting']], $this->bindings);
    }

    /**
     * Test that the read is scoped to the prefixed table and the schema the
     * reference names.
     *
     * The catalogue holds the table under the name it was created with, which
     * carries the connection's prefix the model's own name does not.
     *
     * @return void
     */
    public function testScopesTheReadToThePrefixedTableAndItsSchema(): void
    {
        (new IndexEligibilityInspector)->inspect('reporting.users', $this->connection('pgsql', [], 'api_'));

        self::assertSame([['reporting', 'api_users']], $this->bindings);
    }

    /**
     * Test that a read the engine refuses reports nothing rather than refusing
     * every index.
     *
     * The column naming these facts is not offered by every server the
     * inspector is reachable on, and a read that fails there must not take a
     * proof down with it.
     *
     * @return void
     */
    public function testAReadTheEngineRefusesReportsNothing(): void
    {
        $connection = self::createStub(Connection::class);

        $connection->method('getDriverName')->willReturn('mysql');
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('selectFromWriteConnection')->willThrowException(
            new QueryException('mysql', 'select', [], new \RuntimeException('access denied')),
        );

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->describes('users_name_index'));
        self::assertFalse($eligibility->lacksColumnOrder('users_name_index'));
    }

    /**
     * Test that a row the engine returns without a readable name is passed over
     * rather than stopping the read.
     *
     * @return void
     */
    public function testReadsPastARowWithNoReadableName(): void
    {
        $connection = $this->connection('mysql', [
            (object) ['name' => null, 'disregarded' => 1, 'expressed' => 0],
            (object) ['name' => 'users_hidden_index', 'disregarded' => 1, 'expressed' => 0],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->disregards('users_hidden_index'));
    }

    /**
     * Test that a flag is read as the number it spells rather than as a
     * non-empty string.
     *
     * A driver may hand back a number as text, and any label would be truthy
     * read as one, so the negative answer has to survive the journey.
     *
     * @return void
     */
    public function testReadsAFlagAsTheNumberItSpells(): void
    {
        $connection = $this->connection('mysql', [
            (object) ['name' => 'users_name_index', 'disregarded' => '0', 'expressed' => '0', 'unordered' => '0'],
            (object) ['name' => 'users_hidden_index', 'disregarded' => '1', 'expressed' => '0', 'unordered' => '0'],
            (object) ['name' => 'users_name_prefix_index', 'disregarded' => '0', 'expressed' => '0', 'unordered' => '1'],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertTrue($eligibility->describes('users_name_index'));
        self::assertFalse($eligibility->lacksColumnOrder('users_name_index'));
        self::assertTrue($eligibility->disregards('users_hidden_index'));
        self::assertTrue($eligibility->lacksColumnOrder('users_name_prefix_index'));
    }

    /**
     * Test that a key collated apart is read from a flag spelled as text as
     * well as from a number.
     *
     * @return void
     */
    public function testReadsAKeyCollatedApartFromAFlagInEitherSpelling(): void
    {
        $connection = $this->connection('pgsql', [
            (object) ['name' => 'users_name_index', 'recollated' => '0'],
            (object) ['name' => 'users_name_c_index', 'recollated' => '1'],
            (object) ['name' => 'users_label_c_index', 'recollated' => 1],
        ]);

        $eligibility = (new IndexEligibilityInspector)->inspect('users', $connection);

        self::assertFalse($eligibility->collatesApart('users_name_index'));
        self::assertTrue($eligibility->describes('users_name_index'));
        self::assertTrue($eligibility->collatesApart('users_name_c_index'));
        self::assertTrue($eligibility->collatesApart('users_label_c_index'));
        self::assertFalse($eligibility->describes('users_label_c_index'));
    }

    /**
     * Build a connection naming the given engine and returning the given rows.
     *
     * @param  string|null  $driver
     * @param  array<int, object>  $rows
     * @param  string  $prefix
     * @return \Illuminate\Database\Connection
     */
    private function connection(?string $driver, array $rows = [], string $prefix = ''): Connection
    {
        $connection = self::createStub(Connection::class);

        $connection->method('getDriverName')->willReturn($driver);
        $connection->method('getTablePrefix')->willReturn($prefix);
        $connection->method('selectFromWriteConnection')->willReturnCallback(function (string $query, array $bindings = []) use ($rows): array {

            $this->statements[] = $query;
            $this->bindings[]   = $bindings;

            return $rows;
        });

        return $connection;
    }
}
