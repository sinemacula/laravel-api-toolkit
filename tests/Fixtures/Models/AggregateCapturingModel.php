<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

/**
 * Minimal fake model that captures loadSum/loadAvg calls for unit testing.
 *
 * Converts the 'relation as alias' relation argument to the
 * presentKey_metric_column attribute name that ValueResolver expects, then
 * stores a fixed sentinel value. Satisfies all method_exists guards in
 * ApiResource without requiring a real Eloquent database connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class AggregateCapturingModel
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * Magic getter for attribute access.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Magic isset for attribute existence check.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * No-op for the eager-load relations path.
     *
     * @param  mixed  $with
     * @return static
     */
    public function loadMissing(mixed $with): static
    {
        return $this;
    }

    /**
     * No-op; the counts field is not requested in aggregate tests.
     *
     * @param  mixed  $relations
     * @return static
     */
    public function loadCount(mixed $relations): static
    {
        return $this;
    }

    /**
     * Capture a sum aggregate call and store a fixed sentinel value.
     *
     * Mirrors Eloquent, which uses the "as" alias verbatim as the result
     * attribute, so the alias is stored as-is for ValueResolver to read back.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  mixed  $relations
     * @param  string  $column
     * @return static
     */
    public function loadSum(mixed $relations, string $column): static
    {
        $this->attributes[$this->resolveAlias($relations)] = 42.0;

        return $this;
    }

    /**
     * Capture an average aggregate call and store a fixed sentinel value.
     *
     * Mirrors Eloquent, which uses the "as" alias verbatim as the result
     * attribute, so the alias is stored as-is for ValueResolver to read back.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  mixed  $relations
     * @param  string  $column
     * @return static
     */
    public function loadAvg(mixed $relations, string $column): static
    {
        $this->attributes[$this->resolveAlias($relations)] = 3.5;

        return $this;
    }

    /**
     * Return the current attribute map.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Derive the present-key alias from the relation argument.
     *
     * The 'relation as alias' string form yields the alias segment after ' as
     * '. An array uses the key in the same format.
     *
     * @param  mixed  $relations
     * @return string
     */
    private function resolveAlias(mixed $relations): string
    {
        if (is_array($relations)) {
            $first = array_key_first($relations);
            $rel   = $first !== null ? (string) $first : '';
        } else {
            $rel = is_string($relations) ? $relations : '';
        }

        return explode(' as ', $rel)[1] ?? $rel;
    }
}
