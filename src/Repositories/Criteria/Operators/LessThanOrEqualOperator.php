<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

/**
 * Filter operator handler for the $le (less than or equal) token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LessThanOrEqualOperator extends ComparisonOperator
{
    /**
     * Return the SQL comparison operator symbol.
     *
     * @return string
     */
    #[\Override]
    protected function operator(): string
    {
        return '<=';
    }
}
