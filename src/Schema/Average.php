<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Relation aggregate definition that loads a withAvg() aggregate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Average extends AggregateDefinition
{
    /**
     * Return the metric identifier for average aggregates.
     *
     * @return string
     */
    #[\Override]
    protected static function metric(): string
    {
        return 'avg';
    }
}
