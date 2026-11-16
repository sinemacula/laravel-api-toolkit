<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

/**
 * Minimal fake model that captures loadAggregate calls for unit testing.
 *
 * Stores a fixed sentinel per function under the 'relation as alias' alias, as
 * Eloquent does, so the resolver reads it back without a database connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class AggregateCapturingModel
{
    /** @var array<string, float|int> The sentinel stored for each function */
    private const array SENTINELS = ['count' => 2, 'sum' => 42, 'avg' => 3.5];

    /** @var array<int, array{mixed, string, string}> The loadAggregate calls, in order */
    public array $calls = [];

    /**
     * Create the fake with the given attributes already loaded.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(

        /** The loaded attributes */
        private array $attributes = [],
    ) {}

    /**
     * No-op for the eager-load relations path.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  mixed  $with
     * @return self
     */
    public function loadMissing(mixed $with): self
    {
        return $this;
    }

    /**
     * Capture an aggregate call and store the function's sentinel value.
     *
     * @param  mixed  $relations
     * @param  string  $column
     * @param  string  $function
     * @return self
     */
    public function loadAggregate(mixed $relations, string $column, string $function): self
    {
        $this->calls[] = [$relations, $column, $function];

        $this->attributes[$this->resolveAlias($relations)] = self::SENTINELS[$function] ?? 1;

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
     * Derive the alias from the 'relation as alias' relation argument.
     *
     * @param  mixed  $relations
     * @return string
     */
    private function resolveAlias(mixed $relations): string
    {
        $specification = is_array($relations) ? (string) array_key_first($relations) : (is_string($relations) ? $relations : '');

        return explode(' as ', $specification)[1] ?? $specification;
    }
}
