<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

/**
 * Immutable value object for filter dispatch state.
 *
 * Captures the logical operator, relation scope flag, and nesting depth at each
 * level of the recursive filter dispatch. Each named constructor returns a new
 * instance, leaving the parent unmodified.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FilterContext
{
    /**
     * Constructor.
     *
     * @param  string|null  $logicalOperator
     * @param  bool  $inRelation
     * @param  int  $depth
     * @return void
     */
    private function __construct(

        /** The current logical operator ('$and', '$or', or null) */
        private readonly ?string $logicalOperator,

        /** Whether the current context is inside a relation scope */
        private readonly bool $inRelation,

        /** The nesting depth (0 = top level) */
        private readonly int $depth,

    ) {}

    /**
     * Create the initial context for top-level filter dispatch.
     *
     * @return self
     */
    public static function root(): self
    {
        return new self(null, false, 0);
    }

    /**
     * Create a context for a nested logical group.
     *
     * @param  string  $logicalOperator
     * @param  self  $parent
     * @return self
     */
    public static function nested(string $logicalOperator, self $parent): self
    {
        return new self($logicalOperator, $parent->inRelation, $parent->depth + 1);
    }

    /**
     * Create a context for entering a relation scope.
     *
     * @param  self  $parent
     * @return self
     */
    public static function forRelation(self $parent): self
    {
        return new self($parent->logicalOperator, true, $parent->depth);
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
     * Return whether inside a relation scope.
     *
     * @return bool
     */
    public function isInRelation(): bool
    {
        return $this->inRelation;
    }

    /**
     * Return the nesting depth (0 = top level).
     *
     * @return int
     */
    public function getDepth(): int
    {
        return $this->depth;
    }
}
