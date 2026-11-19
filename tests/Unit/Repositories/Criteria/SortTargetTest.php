<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Repositories\Criteria\SortTarget;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;

/**
 * Tests for the SortTarget value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SortTarget::class)]
final class SortTargetTest extends TestCase
{
    /**
     * Test that a column target names the column and no aggregate.
     *
     * @return void
     */
    public function testColumnTargetNamesTheColumnAndNoAggregate(): void
    {
        $target = SortTarget::column('name');

        self::assertSame(['name', null, null, null, false], $this->describe($target));
        self::assertFalse($target->isAggregate());
    }

    /**
     * Test that an aggregate target carries the aggregate's relation, function,
     * column and constraint, counting rows over every column.
     *
     * @return void
     */
    public function testAggregateTargetCarriesTheDeclaredAggregate(): void
    {
        $constraint = static function (): void {};

        $count = SortTarget::aggregate(new CompiledAggregateDefinition('posts_count', 'posts', AggregateFunction::COUNT, null, $constraint, false, []));
        $sum   = SortTarget::aggregate(new CompiledAggregateDefinition('spend', 'orders', AggregateFunction::SUM, 'total', null, false, []));

        self::assertSame(['*', 'posts', AggregateFunction::COUNT, $constraint, false], $this->describe($count));
        self::assertSame(['total', 'orders', AggregateFunction::SUM, null, false], $this->describe($sum));
        self::assertTrue($count->isAggregate());
    }

    /**
     * Test that a related target reads the related column as stored through a
     * to-one relation.
     *
     * @return void
     */
    public function testRelatedTargetReadsTheStoredColumnThroughAToOneRelation(): void
    {
        $target = SortTarget::related('customer', 'name');

        self::assertSame(['name', 'customer', null, null, true], $this->describe($target));
        self::assertTrue($target->isAggregate());
    }

    /**
     * Describe a target as its column, relation, function, constraint and
     * to-one flag.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\SortTarget  $target
     * @return list<mixed>
     */
    private function describe(SortTarget $target): array
    {
        return [$target->column, $target->relation, $target->function, $target->constraint, $target->toOne];
    }
}
