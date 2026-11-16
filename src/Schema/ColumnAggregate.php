<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Base for relation aggregates computed over one related column.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ColumnAggregate extends AggregateDefinition
{
    /**
     * Define an aggregate over the given relation and related column.
     *
     * @param  string  $relation
     * @param  string  $column
     * @param  string|null  $alias
     * @return static
     */
    public static function of(string $relation, string $column, ?string $alias = null): static
    {
        return new static($relation, $column, $alias);
    }
}
