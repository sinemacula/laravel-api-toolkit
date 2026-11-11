<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Implemented by a filter operator that fans one value out into several
 * predicates, so the item cap measures the expanded list.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ExpandsValueList
{
    /**
     * Return the number of items the operator reads out of the given value.
     *
     * @param  mixed  $value
     * @return int
     */
    public function countValueItems(mixed $value): int;
}
