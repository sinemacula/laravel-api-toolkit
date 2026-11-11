<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

/**
 * Stand-in resource passed to a guard to detect whether it reads the row.
 *
 * Unmodelled access throws, which the caller treats as a per-item read.
 *
 * @internal
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class RowGuardProbe implements \IteratorAggregate, \Stringable
{
    /** @var bool Whether the guard interacted with the probe */
    private bool $touched = false;

    /**
     * Record a property read.
     *
     * @param  string  $name
     * @return self
     */
    public function __get(string $name): self
    {
        $this->touched = true;

        return $this;
    }

    /**
     * Record an isset check.
     *
     * @param  string  $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        $this->touched = true;

        return true;
    }

    /**
     * Record a method call.
     *
     * @param  string  $name
     * @param  array<int, mixed>  $arguments
     * @return self
     *
     * @SuppressWarnings("php:S4144")
     */
    public function __call(string $name, array $arguments): self
    {
        $this->touched = true;

        return $this;
    }

    /**
     * Record a string coercion.
     *
     * @return string
     */
    #[\Override]
    public function __toString(): string
    {
        $this->touched = true;

        return '';
    }

    /**
     * Determine whether the guard interacted with the probe.
     *
     * @return bool
     */
    public function wasTouched(): bool
    {
        return $this->touched;
    }

    /**
     * Record an iteration.
     *
     * @return \Traversable<int, mixed>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        $this->touched = true;

        return new \ArrayIterator([]);
    }
}
