<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

/**
 * Filter operator handler for the $ge (greater than or equal) token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GreaterThanOrEqualOperator extends ComparisonOperator
{
    /**
     * Return the SQL comparison operator symbol.
     *
     * @return string
     */
    #[\Override]
    protected function operator(): string
    {
        return '>=';
    }
}
