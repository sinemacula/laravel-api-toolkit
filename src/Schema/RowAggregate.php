<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Base for relation aggregates computed over the related rows themselves.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class RowAggregate extends AggregateDefinition
{
    /**
     * Define an aggregate over the given relation.
     *
     * @param  string  $relation
     * @param  string|null  $alias
     * @return static
     */
    public static function of(string $relation, ?string $alias = null): static
    {
        return new static($relation, null, $alias);
    }
}
