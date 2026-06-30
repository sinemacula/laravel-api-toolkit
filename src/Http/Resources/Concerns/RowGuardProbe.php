<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

/**
 * Stand-in resource passed to a guard to detect whether it reads the row.
 *
 * A guard receives the resource and the request. Per-row data only ever reaches
 * a guard through the resource argument, so handing the guard this probe in
 * place of a real resource and recording whether it interacts with the probe
 * reveals whether the guard depends on the row (per-item) or only on the
 * request. Every recorded access flips an internal flag and returns a value
 * that lets the guard keep running, so a chained read is observed before it can
 * error. Any access the probe does not model (array access, invocation,
 * counting) throws, which the caller treats as a per-item read by failing
 * closed.
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
    /** @var bool Whether the guard interacted with the probe in any way. */
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
