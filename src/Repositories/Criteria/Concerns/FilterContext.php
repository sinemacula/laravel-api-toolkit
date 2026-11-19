<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

/**
 * Immutable value object locating one level of a filter walk.
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
     * @param  int  $depth
     * @param  string  $pointer
     * @param  string  $path
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterCostBudget|null  $budget
     * @return void
     */
    private function __construct(

        /** The number of levels descended from the root */
        private int $depth = 0,

        /** JSON pointer to the current level within the filter document */
        private string $pointer = '',

        /** Dot path to the current level, as a validation error is keyed */
        private string $path = 'filters',

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
        return new self(budget: $budget);
    }

    /**
     * Create the context for the level below this one, carrying its budget.
     *
     * @param  string  $segment
     * @return self
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function descend(string $segment): self
    {
        $pointer = $this->pointerTo($segment);

        $this->budget?->admitLevel($this->depth + 1, $pointer);

        return new self($this->depth + 1, $pointer, $this->pathTo($segment), $this->budget);
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
        return new self($this->depth, $this->pointerTo($segment), $this->pathTo($segment), $this->budget);
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
        return $this->pointer . '/' . strtr($key, ['~' => '~0', '/' => '~1']);
    }

    /**
     * Return the JSON pointer to the current level.
     *
     * @return string
     */
    public function pointer(): string
    {
        return $this->pointer;
    }

    /**
     * Return the dot path to the current level.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Return the dot path to the given key at the current level.
     *
     * @param  string  $key
     * @return string
     */
    private function pathTo(string $key): string
    {
        return $this->path . '.' . $key;
    }
}
