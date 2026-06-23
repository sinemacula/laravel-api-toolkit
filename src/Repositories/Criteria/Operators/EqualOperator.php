<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

/**
 * Handler for the $eq filter operator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EqualOperator extends ComparisonOperator
{
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
}
