<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\ColumnAggregate;
use SineMacula\ApiToolkit\Schema\Max;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Tests for the ColumnAggregate abstract class, through its subclasses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnAggregate::class)]
final class ColumnAggregateTest extends TestCase
{
    /**
     * Test that of builds the concrete subclass it is called on.
     *
     * @return void
     */
    public function testOfBuildsTheCalledSubclass(): void
    {
        self::assertInstanceOf(Sum::class, Sum::of('orders', 'total'));
        self::assertInstanceOf(Max::class, Max::of('orders', 'total'));
    }

    /**
     * Test that of carries the relation, the column and the alias.
     *
     * @return void
     */
    public function testOfCarriesTheRelationColumnAndAlias(): void
    {
        $array = Max::of('orders', 'placed_at', 'last_order_at')->toArray();

        self::assertSame('orders', $array['__aggregate__:last_order_at']['relation']);
        self::assertSame('placed_at', $array['__aggregate__:last_order_at']['column']);
    }
}
