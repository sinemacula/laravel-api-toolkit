<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ComparisonOperator;
use Tests\TestCase;

/**
 * Tests for the ComparisonOperator base class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ComparisonOperator::class)]
final class ComparisonOperatorTest extends TestCase
{
    /**
     * Test that a comparison takes a single value.
     *
     * @return void
     */
    public function testValueShapeIsScalar(): void
    {
        $operator = new class extends ComparisonOperator {
            /**
             * Return the SQL comparison operator symbol.
             *
             * @return string
             */
            #[\Override]
            protected function operator(): string
            {
                return '=';
            }
        };

        self::assertSame(FilterValueShape::SCALAR, $operator->valueShape());
    }
}
