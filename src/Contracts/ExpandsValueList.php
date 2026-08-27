<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Value-list expansion contract for filter operator handlers.
 *
 * Implemented by an operator that fans a single value out into more than one
 * predicate, so the dispatcher measures the list the handler will actually
 * materialise against the item cap rather than the shape of the raw value. An
 * operator that does not implement this contract is measured as one item per
 * non-list value.
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
