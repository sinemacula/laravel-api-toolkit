<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;

/**
 * Sort eligibility integration suite for the MySQL engine.
 *
 * The engine will not use an index whose first key part holds only a prefix of
 * its column to deliver that column's order, while still finding rows through
 * it, so the sort proof and the search proof have to part ways over one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
final class MySqlSortIndexEligibilityTest extends EngineSortIndexEligibilityTestCase
{
    /**
     * Test that an index keyed on a prefix of the column is refused for
     * sorting.
     *
     * @return void
     */
    public function testRefusesAnIndexKeyedOnAPrefixOfTheColumn(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label(20))');

        $this->assertRefusedForLackingTheColumnsOrder();
    }

    /**
     * Test that a prefix key after the leading one leaves the leading column's
     * order intact.
     *
     * @return void
     */
    public function testAcceptsAPrefixKeyAfterTheLeadingOne(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label, body(20))');

        $this->assertAcceptedAsHoldingTheColumnsOrder();
    }

    /**
     * Test that an ordinary index over the whole column is accepted, so the
     * refusal above answers to the prefix rather than to the table.
     *
     * @return void
     */
    public function testAcceptsAnIndexOverTheWholeColumn(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label)');

        $this->assertAcceptedAsHoldingTheColumnsOrder();
    }

    /**
     * Test that an index keyed on a prefix of the column still backs a search
     * finding rows by that column.
     *
     * @return void
     */
    public function testAPrefixKeyedIndexStillBacksASearch(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label(20))');

        $driver = new MySqlNgramSearchDriver;

        self::assertSame([], $driver->indexDefects(SearchStrategy::EXACT, ['label'], self::TABLE, DB::connection()));
        self::assertSame([], $driver->indexDefects(SearchStrategy::PREFIX, ['label'], self::TABLE, DB::connection()));
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'mysql';
    }
}
