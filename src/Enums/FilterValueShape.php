<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * The shape of the value a filter operator accepts.
 *
 * Null is never a value: a missing value is matched by $null alone.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum FilterValueShape
{
    /** One scalar */
    case SCALAR;

    /** A list of scalars, empty allowed */
    case LIST;

    /** A list of exactly two scalars, the low and high bounds */
    case BOUNDS;

    /** Exactly true, as the operator token alone names the predicate */
    case PRESENCE;

    /** One scalar or a non-empty list of them, each a JSON element whatever the column's type */
    case ELEMENTS;
}
