<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Field schema helpers for scalar and accessor fields.
 *
 * Provides guard and transformer support, optional aliasing, and Arrayable
 * definitions suitable for direct use in resource schemas.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
final class Field extends BaseDefinition
{
    /** @var mixed Compute callable for dynamic field values */
    private mixed $compute = null;

    /**
     * Prevent direct instantiation.
     *
     * @param  string  $name
     * @param  mixed|null  $accessor
     * @param  string|null  $alias
     */
    private function __construct(

        /** The field's canonical name */
        private readonly string $name,

        /** Accessor for computed or nested values */
        private readonly mixed $accessor = null,

        /** Optional alias to expose this field under */
        private ?string $alias = null

    ) {}

    /**
     * Define a scalar field by name.
     *
     * @param  string  $field
     * @param  string|null  $alias
     * @return static
     */
    public static function scalar(string $field, ?string $alias = null): self
    {
        return new self($field, null, $alias);
    }

    /**
     * Define an accessor field by name.
     *
     * @param  string  $field
     * @param  callable|string  $accessor
     * @param  string|null  $alias
     * @return self
     */
    public static function accessor(string $field, callable|string $accessor, ?string $alias = null): self
    {
        return new self($field, $accessor, $alias);
    }

    /**
     * Define a timestamp field by name.
     *
     * @param  string  $field
     * @param  string|null  $alias
     * @return self
     */
    public static function timestamp(string $field, ?string $alias = null): self
    {
        return self::accessor($field, static fn (JsonResource $resource) => $resource->{$field}?->toIso8601String(), $alias);
    }

    /**
     * Define a date field by name.
     *
     * @param  string  $field
     * @param  string|null  $alias
     * @return self
     */
    public static function date(string $field, ?string $alias = null): self
    {
        return self::accessor($field, static fn (JsonResource $resource) => $resource->{$field}?->toDateString(), $alias);
    }

    /**
     * Define a computed field by name.
     *
     * @param  string  $field
     * @param  callable|string  $compute
     * @param  string|null  $alias
     * @return self
     */
    public static function compute(string $field, callable|string $compute, ?string $alias = null): self
    {
        $instance          = new self($field, null, $alias);
        $instance->compute = $compute;

        return $instance;
    }

    /**
     * Set or change the alias for this field.
     *
     * @param  string  $alias
     * @return self
     */
    public function alias(string $alias): self
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $key = $this->alias ?? $this->name;

        return [
            $key => array_filter([
                'accessor'     => $this->accessor,
                'compute'      => $this->compute,
                'extras'       => $this->extras ?: null,
                'guards'       => $this->getGuards() ?: null,
                'transformers' => $this->getTransformers() ?: null,
            ], static fn ($value) => $value !== null && $value !== []),
        ];
    }

    /**
     * Merge multiple field definitions into a single normalized array.
     *
     * Later definitions overwrite earlier ones for the same field key.
     *
     * @param  array<int, array<string, array>|Arrayable>  ...$definitions
     * @return array<string, array>
     */
    public static function set(array|Arrayable ...$definitions): array
    {
        $compiled = [];

        foreach ($definitions as $definition) {

            $definition = $definition instanceof Arrayable ? $definition->toArray() : $definition;

            foreach ($definition as $key => $value) {
                $compiled[$key] = $value;
            }
        }

        return $compiled;
    }
}
