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
 * declares, orders exactly as a plain index does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
final class PostgresSortIndexEligibilityTest extends EngineSortIndexEligibilityTestCase
{
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
}
