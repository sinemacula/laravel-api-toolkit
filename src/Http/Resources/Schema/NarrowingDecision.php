<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

/**
 * Discriminated result of a column-narrowing decision.
 *
 * Either narrow (carrying the column projection) or fallback (carrying the
 * optional field key that forced the fall-back, for dev-time diagnostics).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class NarrowingDecision
{
    /**
     * Create a new narrowing decision.
     *
     * @param  bool  $shouldNarrow
     * @param  array<int, string>  $columns
     * @param  string|null  $reason
     */
    private function __construct(

        /** Whether the query should be narrowed to the given columns */
        private bool $shouldNarrow,

        /** The column projection to apply when narrowing */
        private array $columns,

        /** The field key that forced the fall-back, or null */
        private ?string $reason,
    ) {}

    /**
     * Build a narrowing decision carrying the column projection.
     *
     * @param  array<int, string>  $columns
     * @return self
     */
    public static function narrow(array $columns): self
    {
        return new self(true, array_values($columns), null);
    }

    /**
     * Build a fall-back decision carrying the optional reason (the field key
     * that forced fall-back).
     *
     * @param  string|null  $reason
     * @return self
     */
    public static function fallback(?string $reason = null): self
    {
        return new self(false, [], $reason);
    }

    /**
     * Determine whether the query should be narrowed to the column projection.
     *
     * @return bool
     */
    public function shouldNarrow(): bool
    {
        return $this->shouldNarrow;
    }

    /**
     * Return the column projection for this decision.
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Return the field key that forced the fall-back, or null when not set.
     *
     * @return string|null
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
