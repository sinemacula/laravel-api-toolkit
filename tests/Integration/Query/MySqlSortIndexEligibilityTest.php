<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;

/**
 * Sort eligibility integration suite for the MySQL engine.
 *
 * MySQL will not use a prefix-keyed index to order its column, though it still
 * finds rows through it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
#[Group('mysql')]
final class MySqlSortIndexEligibilityTest extends EngineSortIndexEligibilityTestCase
{
    /**
     * Test that an index keyed on a prefix of the column is refused.
     *
     * @return void
     */
    public function testRefusesAnIndexKeyedOnAPrefixOfTheColumn(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label(20))');

        $this->assertRefusedForLackingTheColumnsOrder();
    }

    /**
     * Test that an index led by an expression is refused for the column after
     * it.
     *
     * The shared catalogue drops the expression key, so the index reads as if
     * it led with that column.
     *
     * @return void
     */
    public function testRefusesAnIndexLedByAnExpression(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows ((lower(body)), label)');

        self::assertTrue($this->eligibility()->keysAnExpression(self::INDEX));
        self::assertSame([self::DEFECT], $this->defects());
    }

    /**
     * Test that a prefix key after the leading one keeps the leading order.
     *
     * @return void
     */
    public function testAcceptsAPrefixKeyAfterTheLeadingOne(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label, body(20))');

        $this->assertAcceptedAsHoldingTheColumnsOrder();
    }

    /**
     * Test that an ordinary index over the whole column is accepted.
     *
     * @return void
     */
    public function testAcceptsAnIndexOverTheWholeColumn(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label)');

        $this->assertAcceptedAsHoldingTheColumnsOrder();
    }

    /**
     * Test that a prefix-keyed index still backs a search on the column.
     *
     * @return void
     */
    public function testAPrefixKeyedIndexStillBacksASearch(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label(20))');

        $driver = new MySqlNgramSearchDriver;

        self::assertSame([], $driver->indexDefects(SearchStrategy::EXACT, ['label'], self::TABLE, DB::connection()));
        self::assertSame([], $driver->indexDefects(SearchStrategy::PREFIX, ['label'], self::TABLE, DB::connection()));
        self::assertFalse($this->eligibility()->collatesApart(self::INDEX));
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
