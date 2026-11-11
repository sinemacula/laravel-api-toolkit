<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOrEqualOperator;

/**
 * Tests for the LessThanOrEqualOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LessThanOrEqualOperator::class)]
final class LessThanOrEqualOperatorTest extends ComparisonOperatorTestCase
{
    /**
     * Create the operator under test.
     *
     * @return \SineMacula\ApiToolkit\Contracts\FilterOperator
     */
    #[\Override]
    protected function operator(): FilterOperator
    {
        return new LessThanOrEqualOperator;
    }

    /**
     * Return the SQL comparison the operator emits.
     *
     * @return string
     */
    #[\Override]
    protected function sqlOperator(): string
    {
        return '<=';
    }
}
