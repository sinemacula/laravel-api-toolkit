<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

/**
 * Filter operator handler for the $null token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NullOperator extends NullityOperator
{
    /**
     * Return whether the constraint asserts the column is not null.
     *
     * @return bool
     */
    #[\Override]
    protected function negated(): bool
    {
        return false;
    }
}
