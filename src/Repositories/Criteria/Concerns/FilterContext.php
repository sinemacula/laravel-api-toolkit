<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

/**
 * Immutable value object for filter dispatch state.
 *
 * Captures the logical operator in effect at each level of the recursive filter
 * dispatch, along with how far that level sits from the root and where it sits
 * within the filter document. Each named constructor returns a new immutable
 * instance.
 *
 * A context derived with descend() carries the cost budget of the level it came
 * from, so depth and node totals accumulate across the whole walk while the
 * context itself stays immutable. A context built directly by root() or
 * nested() carries no budget and is therefore uncapped.
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
        return new self($logicalOperator, 1, '/' . $logicalOperator);
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
     * Return the number of levels descended from the root.
     *
     * @return int
     */
    public function getDepth(): int
    {
        return $this->depth;
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
