<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

/**
 * Handler for the $gt filter operator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GreaterThanOperator extends ComparisonOperator
{
    /**
     * Return the SQL comparison operator symbol.
     *
     * @return string
     */
    #[\Override]
    protected function operator(): string
    {
        return '>';
    }
}
