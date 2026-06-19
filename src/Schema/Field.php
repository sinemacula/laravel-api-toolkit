<?php

namespace SineMacula\ApiToolkit\Schema;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use SineMacula\ApiToolkit\Exceptions\DuplicateSchemaKeyException;

/**
 * Field schema helpers for scalar and accessor fields.
 *
 * Provides guard and transformer support, optional aliasing, and Arrayable
 * definitions suitable for direct use in resource schemas.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Field extends BaseDefinition
{
    /** @var mixed Compute callable for dynamic field values */
    private mixed $compute = null;

    /** @var bool Whether this field's column is declared filterable */
    private bool $filterable = false;

    /** @var bool Whether this field's column is declared sortable */
    private bool $sortable = false;

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
        private ?string $alias = null,

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
     * @param  (callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): mixed)|string  $accessor
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
        $definition = self::accessor($field, static function ($resource) use ($field): ?string {

            $value = data_get($resource, $field);

            return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
        }, $alias);

        $definition->openapi()->type('string')->format('date-time')->nullable();

        return $definition;
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
        $definition = self::accessor($field, static function ($resource) use ($field): ?string {

            $value = data_get($resource, $field);

            return $value instanceof CarbonInterface ? $value->toDateString() : null;
        }, $alias);

        $definition->openapi()->type('string')->format('date')->nullable();

        return $definition;
    }

    /**
     * Define a computed field by name.
     *
     * @param  string  $field
     * @param  (callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): mixed)|string  $compute
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
     * Declare this field's column as filterable.
     *
     * @return self
     */
    public function filterable(): self
    {
        $this->filterable = true;

        return $this;
    }

    /**
     * Declare this field's column as sortable.
     *
     * @return self
     */
    public function sortable(): self
    {
        $this->sortable = true;

        return $this;
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function toArray(): array
    {
        $key = $this->alias ?? $this->name;

        return [
            $key => array_filter([
                'accessor'     => $this->accessor,
                'compute'      => $this->compute,
                'filterable'   => $this->filterable ? $this->name : null,
                'sortable'     => $this->sortable ? $this->name : null,
                'extras'       => $this->extras ?: null,
                'needs'        => $this->needs ?: null,
                'guards'       => $this->getGuards() ?: null,
                'transformers' => $this->getTransformers() ?: null,
                'openapi'      => $this->getOpenApiDeclaration()?->toSchema(),
            ], static fn ($value) => $value !== null && $value !== []),
        ];
    }

    /**
     * Merge multiple field definitions into a single normalized array.
     *
     * @param  array<string, array<string, mixed>>|\Illuminate\Contracts\Support\Arrayable<string, array<string, mixed>>  ...$definitions
     * @return array<string, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\DuplicateSchemaKeyException
     */
    public static function set(array|Arrayable ...$definitions): array
    {
        $compiled = [];

        foreach ($definitions as $definition) {

            $definition = $definition instanceof Arrayable ? $definition->toArray() : $definition;

            foreach ($definition as $key => $value) {

                if (array_key_exists($key, $compiled)) {
                    throw new DuplicateSchemaKeyException(sprintf('Duplicate schema key "%s" detected in Field::set()', $key));
                }

                $compiled[$key] = $value;
            }
        }

        return $compiled;
    }
}
