<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use SineMacula\ApiToolkit\Query\QueryCostLimits;

/**
 * Mutable cost budget shared by every level of a single filter walk.
 *
 * Caps are enforced during descent, so an oversized document fails part-way.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FilterCostBudget
{
    /** @var int The number of nodes admitted so far */
    private int $nodes = 0;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Query\QueryCostLimits  $limits
     * @return void
     */
    public function __construct(

        /** The caps the walk is measured against */
        private readonly QueryCostLimits $limits,
    ) {}

    /**
     * Admit a descent to the given depth.
     *
     * @param  int  $depth
     * @param  string  $pointer
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function admitLevel(int $depth, string $pointer): void
    {
        $this->limits->enforce(QueryCostLimits::MAX_DEPTH, $depth, 'filters', $pointer);
    }

    /**
     * Admit one more node.
     *
     * @param  string  $pointer
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function admitNode(string $pointer): void
    {
        $this->limits->enforce(QueryCostLimits::MAX_NODES, ++$this->nodes, 'filters', $pointer);
    }
}
