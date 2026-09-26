<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;

/**
 * Sort eligibility integration suite for the PostgreSQL engine.
 *
 * A B-tree leading with the column is only as good for sorting as the order its
 * leading key is kept in. A key kept by a pattern operator class, or under a
 * collation other than the column's, cannot answer an ordered read of it, while
 * an alias of the default operator class, or a collation the column itself
 * declares, orders exactly as a plain index does. A key collated apart cannot
 * answer an equality over the column either, while a pattern-class key can.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
final class PostgresSortIndexEligibilityTest extends EngineSortIndexEligibilityTestCase
{
    /** @var string The defect an exact search no usable index leads with draws */
    private const string SEARCH_DEFECT = 'Column "label" is declared searchable with the "exact" strategy, '
        . 'which needs an index leading with that column on table "sort_eligibility_rows"';

    /**
     * Test that an index keyed through the text pattern operator class is
     * refused for sorting.
     *
     * @return void
     */
    public function testRefusesAnIndexKeyedThroughTheTextPatternClass(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label text_pattern_ops)');

        $this->assertRefusedForLackingTheColumnsOrder();
    }

    /**
     * Test that an index keyed through the varchar pattern operator class is
     * refused for sorting.
     *
     * @return void
     */
    public function testRefusesAnIndexKeyedThroughTheVarcharPatternClass(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label varchar_pattern_ops)');

        $this->assertRefusedForLackingTheColumnsOrder();
    }

    /**
     * Test that an index keyed through an alias sharing the default operator
     * class's family is accepted.
     *
     * @return void
     */
    public function testAcceptsAnIndexKeyedThroughAnAliasOfTheDefaultClass(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label varchar_ops)');

        $this->assertAcceptedAsHoldingTheColumnsOrder();
    }

    /**
     * Test that an index collated apart from its column is refused for sorting.
     *
     * @return void
     */
    public function testRefusesAnIndexCollatedApartFromItsColumn(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label collate "C")');

        $this->assertRefusedForLackingTheColumnsOrder();
    }

    /**
     * Test that a plain index over a column declaring its own collation is
     * accepted, since the index inherits that collation.
     *
     * @return void
     */
    public function testAcceptsAPlainIndexOverAColumnDeclaringItsOwnCollation(): void
    {
        $this->createTable('C');

        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label)');

        $this->assertAcceptedAsHoldingTheColumnsOrder();
    }

    /**
     * Test that a pattern operator class on a later key leaves the leading
     * column's order intact.
     *
     * @return void
     */
    public function testAcceptsAPatternClassOnALaterKey(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label, body text_pattern_ops)');

        $this->assertAcceptedAsHoldingTheColumnsOrder();
    }

    /**
     * Test that an expression keyed through a pattern operator class is left to
     * the fact reporting the expression.
     *
     * @return void
     */
    public function testLeavesAnExpressionKeyToTheFactReportingIt(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (lower(label) text_pattern_ops)');

        $eligibility = $this->eligibility();

        self::assertTrue($eligibility->keysAnExpression(self::INDEX));
        self::assertFalse($eligibility->lacksColumnOrder(self::INDEX));
        self::assertSame([self::DEFECT], $this->defects());
    }

    /**
     * Test that a plain index over the column is accepted, so each refusal
     * above answers to the key rather than to the table.
     *
     * @return void
     */
    public function testAcceptsAPlainIndexOverTheColumn(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label)');

        $this->assertAcceptedAsHoldingTheColumnsOrder();
    }

    /**
     * Test that an index keyed through a pattern operator class still backs an
     * equality search over the column.
     *
     * @return void
     */
    public function testAPatternKeyedIndexStillBacksAnEqualitySearch(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label text_pattern_ops)');

        $defects = (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['label'], self::TABLE, DB::connection());

        self::assertSame([], $defects);
        self::assertFalse($this->eligibility()->collatesApart(self::INDEX));
    }

    /**
     * Test that an index collated apart from its column backs no equality
     * search over the column.
     *
     * @return void
     */
    public function testAnIndexCollatedApartBacksNoEqualitySearch(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label collate "C")');

        $defects = (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['label'], self::TABLE, DB::connection());

        self::assertSame(['label' => [self::SEARCH_DEFECT]], $defects);
        self::assertTrue($this->eligibility()->collatesApart(self::INDEX));
    }

    /**
     * Test that a plain index over a column declaring its own collation backs
     * an equality search, since the index inherits that collation.
     *
     * @return void
     */
    public function testAPlainIndexOverAColumnDeclaringItsOwnCollationBacksAnEqualitySearch(): void
    {
        $this->createTable('C');

        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label)');

        $defects = (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['label'], self::TABLE, DB::connection());

        self::assertSame([], $defects);
        self::assertFalse($this->eligibility()->collatesApart(self::INDEX));
    }

    /**
     * Test that the planner answers an equality over the column from a plain
     * index and never from one collated apart, which is what the refusal of the
     * latter rests on.
     *
     * @return void
     */
    public function testThePlannerAnswersAnEqualityOnlyFromAKeyCarryingTheColumnsCollation(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label collate "C")');

        self::assertStringNotContainsString('Index Cond', $this->equalityPlan());

        DB::statement('drop index sort_eligibility_rows_label_index');
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label)');

        self::assertStringContainsString('Index Cond', $this->equalityPlan());
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'pgsql';
    }

    /**
     * Return the plan the engine chooses for an equality over the column, with
     * a sequential scan priced out so any index able to answer it is taken.
     *
     * @return string
     */
    private function equalityPlan(): string
    {
        DB::statement('set enable_seqscan = off');

        try {
            $rows = DB::select('explain select * from sort_eligibility_rows where label = ?', ['anything']);
        } finally {
            DB::statement('reset enable_seqscan');
        }

        return implode("\n", array_map(static fn (object $row): string => (string) array_values((array) $row)[0], $rows));
    }
}
