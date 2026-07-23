<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

/**
 * Immutable value object for filter dispatch state.
 *
 * Captures the logical operator in effect at each level of the recursive filter
 * dispatch. Each named constructor returns a new immutable instance.
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
     * @return void
     */
    private function __construct(

        /** The current logical operator ('$and', '$or', or null) */
        private readonly ?string $logicalOperator,
    ) {}

    /**
     * Create the initial context for top-level filter dispatch.
     *
     * @return self
     */
    public static function root(): self
    {
        return new self(null);
    }

    /**
     * Create a context for a nested logical group.
     *
     * @param  string  $logicalOperator
     * @return self
     */
    public static function nested(string $logicalOperator): self
    {
        return new self($logicalOperator);
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
