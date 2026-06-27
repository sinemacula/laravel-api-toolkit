<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Typed representation of a single compiled aggregate (sum / average)
 * definition.
 *
 * Replaces the untyped associative arrays previously used in the schema cache,
 * providing typed access to all resolved aggregate properties alongside the
 * column and metric discriminator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CompiledAggregateDefinition
{
    /**
     * Create a new compiled aggregate definition.
     *
     * @param  string  $presentKey
     * @param  string  $relation
     * @param  string  $column
     * @param  string  $metric
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null  $constraint
     * @param  bool  $isDefault
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     */
    public function __construct(

        /** The key used in the JSON response */
        public string $presentKey,

        /** The Eloquent relation to aggregate */
        public string $relation,

        /** The database column to aggregate */
        public string $column,

        /** The aggregate metric identifier ('sum' or 'avg') */
        public string $metric,

        /** Optional query constraint for the aggregate */
        public ?\Closure $constraint,

        /** Whether this aggregate is included by default */
        public bool $isDefault,

        /** Guard closures that control visibility */
        public array $guards,
    ) {}
}
