<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

use SineMacula\ApiToolkit\Enums\FilterValueShape;

/**
 * Implemented by a filter operator whose value has a fixed shape, so the value
 * is checked against it and the column's type before the operator applies.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DeclaresValueShape
{
    /**
     * Return the shape of the value the operator accepts.
     *
     * @return \SineMacula\ApiToolkit\Enums\FilterValueShape
     */
    public function valueShape(): FilterValueShape;
}
