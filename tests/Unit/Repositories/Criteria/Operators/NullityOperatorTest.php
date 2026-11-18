<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullityOperator;
use Tests\TestCase;

/**
 * Tests for the NullityOperator base class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NullityOperator::class)]
final class NullityOperatorTest extends TestCase
{
    /**
     * Test that a nullity check takes only true, as its token names the
     * predicate.
     *
     * @return void
     */
    public function testValueShapeIsPresence(): void
    {
        $operator = new class extends NullityOperator {
            /**
             * Return whether the constraint asserts the column is not null.
             *
             * @return bool
             */
            #[\Override]
            protected function isNegated(): bool
            {
                return false;
            }
        };

        self::assertSame(FilterValueShape::PRESENCE, $operator->valueShape());
    }
}
