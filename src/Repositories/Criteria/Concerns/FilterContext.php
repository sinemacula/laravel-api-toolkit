<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

/**
 * Immutable value object for filter dispatch state.
 *
 * Every context derived from a budgeted root shares its mutable cost budget.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FilterContext
{
    /**
     * Constructor.
     *
     * @param  string|null  $logicalOperator
     * @param  int  $depth
     * @param  string  $pointer
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterCostBudget|null  $budget
     * @return void
     */
    private function __construct(

        /** The current logical operator ('$and', '$or', or null) */
        private ?string $logicalOperator,

        /** The number of levels descended from the root */
        private int $depth = 0,

        /** JSON pointer to the current level within the filter document */
        private string $pointer = '',

        /** The cost budget shared by every level of the walk, if any */
        private ?FilterCostBudget $budget = null,
    ) {}

    /**
     * Create the initial context for top-level filter dispatch.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterCostBudget|null  $budget
     * @return self
     */
    public static function root(?FilterCostBudget $budget = null): self
    {
        return new self(null, budget: $budget);
    }

    /**
     * Create a standalone context for a nested logical group.
     *
     * @param  string  $logicalOperator
     * @return self
     */
    public static function nested(string $logicalOperator): self
    {
        return new self($logicalOperator, pointer: '/' . $logicalOperator);
    }

    /**
     * Create the context for the level below this one, carrying its budget.
     *
     * @param  string  $segment
     * @param  string|null  $logicalOperator
     * @return self
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function descend(string $segment, ?string $logicalOperator): self
    {
        $pointer = $this->pointerTo($segment);

        $this->budget?->admitLevel($this->depth + 1, $pointer);

        return new self($logicalOperator, $this->depth + 1, $pointer, $this->budget);
    }

    /**
     * Create the context for the given segment of the current level.
     *
     * Moves the pointer without the depth, so no level of the budget is spent.
     *
     * @param  string  $segment
     * @return self
     */
    public function at(string $segment): self
    {
        return new self($this->logicalOperator, $this->depth, $this->pointerTo($segment), $this->budget);
    }

    /**
     * Admit the given key at the current level as one more node.
     *
     * @param  string  $key
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function admit(string $key): void
    {
        $this->budget?->admitNode($this->pointerTo($key));
    }

    /**
     * Return the JSON pointer to the given key at the current level.
     *
     * @param  string  $key
     * @return string
     */
    public function pointerTo(string $key): string
    {
        return $this->pointer . '/' . $key;
    }

    /**
     * Return the current logical operator.
     *
     * @return string|null
     */
    public function getLogicalOperator(): ?string
    {
        return $this->logicalOperator;
    }

    /**
     * Determine whether the current group combines its conditions with OR.
     *
     * @return bool
     */
    public function isOr(): bool
    {
        return $this->logicalOperator === '$or';
    }

    /**
     * Resolve the query-builder boolean connective for the current group.
     *
     * @return string
     */
    public function sqlBoolean(): string
    {
        return $this->isOr() ? 'or' : 'and';
    }
}
