<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;

/**
 * What one admitted order key orders by: a root column, or an aggregate over a
 * relation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SortTarget
{
    /**
     * Create a new sort target.
     *
     * @param  string  $column
     * @param  string|null  $relation
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction|null  $function
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null  $constraint
     * @param  bool  $toOne
     */
    private function __construct(

        /** The root column, or the related column an aggregate reads */
        public string $column,

        /** The relation an aggregate reads through, or null for a root column */
        public ?string $relation = null,

        /** The function an aggregate applies, or null for a root or to-one column */
        public ?AggregateFunction $function = null,

        /** The constraint an aggregate applies to the related rows */
        public ?\Closure $constraint = null,

        /** Whether the relation must render the single row the order reads */
        public bool $toOne = false,
    ) {}

    /**
     * Order by a root column.
     *
     * @param  string  $column
     * @return self
     */
    public static function column(string $column): self
    {
        return new self($column);
    }

    /**
     * Order by a declared relation aggregate, as it renders.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $aggregate
     * @return self
     */
    public static function aggregate(CompiledAggregateDefinition $aggregate): self
    {
        return new self($aggregate->column ?? '*', $aggregate->relation, $aggregate->function, $aggregate->constraint);
    }

    /**
     * Order by a column of the single row a to-one relation renders.
     *
     * @param  string  $relation
     * @param  string  $column
     * @return self
     */
    public static function related(string $relation, string $column): self
    {
        return new self($column, $relation, toOne: true);
    }

    /**
     * Determine whether the target orders by an aggregate over a relation.
     *
     * @return bool
     *
     * @phpstan-assert-if-true !null $this->relation
     */
    public function isAggregate(): bool
    {
        return $this->relation !== null;
    }
}
