<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Base class for schema definitions.
 *
 * @author      Ben Carey
 * @copyright   2025 Sine Macula
 */
abstract class BaseDefinition implements Arrayable
{
    /** @var array<int, callable(ApiResource, ?Request): bool> Guards for conditional inclusion */
    protected array $guards = [];

    /** @var array<int, callable(ApiResource, mixed): mixed> Transformers for modifying resolved values */
    protected array $transformers = [];

    /** @var array<int, string> Extra eager-load paths */
    protected array $extras = [];

    /**
     * Add a guard condition — return false to suppress the field.
     *
     * @param  callable(ApiResource, ?Request): bool  $guard
     * @return static
     */
    public function guard(callable $guard): static
    {
        $this->guards[] = $guard;

        return $this;
    }

    /**
     * Get the guards attached to this definition.
     *
     * @return array<int, callable(ApiResource, ?Request): bool>
     */
    public function getGuards(): array
    {
        return $this->guards;
    }

    /**
     * Add a transformer to modify the resolved value.
     *
     * @param  callable(ApiResource, mixed): mixed  $transformer
     * @return static
     */
    public function transform(callable $transformer): static
    {
        $this->transformers[] = $transformer;

        return $this;
    }

    /**
     * Get the transformers attached to this definition.
     *
     * @return array<int, callable(ApiResource, mixed): mixed>
     */
    public function getTransformers(): array
    {
        return $this->transformers;
    }

    /**
     * Provide additional eager-load paths required by this field.
     *
     * @param  string  ...$paths
     * @return self
     */
    public function extras(string ...$paths): self
    {
        $this->extras = array_values(array_unique([...$this->extras, ...$paths]));

        return $this;
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function toArray(): array;
}
