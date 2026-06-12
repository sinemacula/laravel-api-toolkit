<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

/**
 * Filter operator handler for the $lt (less than) token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LessThanOperator extends ComparisonOperator
{
    /**
     * Return the SQL comparison operator symbol.
     *
     * @return string
     */
    #[\Override]
    protected function operator(): string
    {
        return '<';
    }
}
