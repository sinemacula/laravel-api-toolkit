<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use SineMacula\ApiToolkit\Enums\AggregateFunction;

/**
 * Relation aggregate that loads whether any related row exists.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Exists extends RowAggregate
{
    /**
     * Return the SQL function this aggregate applies.
     *
     * @return \SineMacula\ApiToolkit\Enums\AggregateFunction
     */
    #[\Override]
    public function function(): AggregateFunction
    {
        return AggregateFunction::EXISTS;
    }
}
