<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Relation aggregate definition that loads a withSum() aggregate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Sum extends AggregateDefinition
{
    /**
     * Return the metric identifier for sum aggregates.
     *
     * @return string
     */
    #[\Override]
    protected static function metric(): string
    {
        return 'sum';
    }
}
