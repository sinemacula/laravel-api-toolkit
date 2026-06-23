<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

/**
 * Filter operator handler for the $notNull token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NotNullOperator extends NullityOperator
{
    /**
     * Return whether the constraint asserts the column is not null.
     *
     * @return bool
     */
    #[\Override]
    protected function isNegated(): bool
    {
        return true;
    }
}
