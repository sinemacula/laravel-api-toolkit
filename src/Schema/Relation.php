<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Relation schema helper for nested resource fields.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Relation extends BaseDefinition
{
    /** @var class-string|null Child ApiResource class */
    private ?string $resource = null;

    /** @var (\Closure(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): mixed)|string|null */
    private \Closure|string|null $accessor = null;

    /** @var array<int, string>|null Child field projection for eager-load planning */
    private ?array $fields = null;

    /** @var (callable(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null Eager-load constraint callback */
    private mixed $constraint = null;

    /** @var bool Whether this relation is declared externally traversable */
    private bool $traversable = false;

    /**
     * Prevent direct instantiation.
     *
     * @param  string  $name
     * @param  (callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): mixed)|string  $resourceOrAccessor
     * @param  string|null  $alias
     */
    private function __construct(

        /** Relation method/name on the model */
        private readonly string $name,

        // Child resource class or relation-local accessor
        callable|string $resourceOrAccessor,

        /** Optional alias to expose this field under */
        private ?string $alias = null,
    ) {
        if (is_string($resourceOrAccessor) && class_exists($resourceOrAccessor)) {
            $this->resource = $resourceOrAccessor;
        } else {
            /** @var (\Closure(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): mixed)|string $accessor */
            $accessor = $resourceOrAccessor;

            $this->accessor = $accessor;
        }
    }

    /**
     * Create a relation definition for the given relation name.
     *
     * @param  string  $name
     * @param  (callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): mixed)|string  $resourceOrAccessor
     * @param  string|null  $alias
     * @return self
     */
    public static function to(string $name, callable|string $resourceOrAccessor, ?string $alias = null): self
    {
        return new self($name, $resourceOrAccessor, $alias);
    }

    /**
     * Set or change the alias for this relation field.
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
     * Specify the child fields considered when planning nested eager loads.
     *
     * @param  array<int, string>  $fields
     * @return self
     */
    public function fields(array $fields): self
    {
        $this->fields = array_values(array_unique($fields));

        return $this;
    }

    /**
     * Apply an eager-load constraint callback to this relation.
     *
     * A MorphTo receives itself, since it replays the calls per loaded type.
     *
     * @param  callable(\Illuminate\Contracts\Database\Eloquent\Builder): void  $constraint
     * @return self
     */
    public function constrain(callable $constraint): self
    {
        $this->constraint = $constraint;

        return $this;
    }

    /**
     * Declare this relation traversable by $has/$hasnt and nested filters.
     *
     * @return self
     */
    public function traversable(): self
    {
        $this->traversable = true;

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
                'relation'    => $this->name,
                'traversable' => $this->traversable ? $this->name : null,
                'resource'    => $this->resource,
                'accessor'    => $this->accessor,
                'extras'      => $this->extras ?: null,
                'needs'       => $this->needs ?: null,
                'fields'      => $this->fields,
                'constraint'  => $this->constraint,
                ...$this->commonAttributes(),
            ], static fn ($value) => $value !== null),
        ];
    }
}
