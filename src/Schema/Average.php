<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Average schema helper for relation aggregate definitions.
 *
 * Produces a schema entry that instructs the toolkit to load a withAvg()
 * aggregate on the declared relation and column pair.
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
