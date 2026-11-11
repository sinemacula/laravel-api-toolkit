<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Concerns;

/**
 * Shared fluent modifiers for count, sum and average definitions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait HasMetricModifiers
{
    /** @var string|null Optional alias to expose this metric under */
    private ?string $alias = null;

    /** @var (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null Optional eager-load constraint for this metric */
    private ?\Closure $constraint = null;

    /** @var bool Whether this metric is included by default when metrics are requested */
    private bool $isDefault = false;

    /**
     * Set or change the alias for this metric.
     *
     * @param  string  $alias
     * @return static
     */
    public function as(string $alias): static
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * Apply an optional query constraint to this metric.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void  $constraint
     * @return static
     */
    public function constrain(\Closure $constraint): static
    {
        $this->constraint = $constraint;

        return $this;
    }

    /**
     * Include this metric by default when no metrics are explicitly selected.
     *
     * @return static
     */
    public function default(): static
    {
        $this->isDefault = true;

        return $this;
    }
}
