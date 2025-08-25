<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Relation schema helper for nested resource fields.
 *
 * Examples:
 *  Relation::to('organization', OrganizationResource::class)
 *  Relation::to('organization', 'name')
 *  Relation::to('organization', 'name', 'organization_name')
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
final class Relation extends BaseDefinition implements Arrayable
{
    /** @var class-string|null Child ApiResource class */
    private ?string $resource = null;

    /** @var string|null */
    private ?string $accessor = null;

    /** @var array<int, string>|null Child field projection for eager-load planning */
    private ?array $fields = null;

    /** @var callable|null Eager-load constraint callback */
    private mixed $constraint = null;

    /**
     * Prevent direct instantiation.
     *
     * @param  string  $name
     * @param  string  $resource_or_accessor
     * @param  string|null  $alias
     */
    private function __construct(

        /** Relation method/name on the model */
        private readonly string $name,

        // Child ApiResource class or relation-local accessor (e.g. "name")
        string $resource_or_accessor,

        /** Optional alias to expose this field under */
        private ?string $alias = null

    ) {
        if (class_exists($resource_or_accessor)) {
            $this->resource = $resource_or_accessor;
        } else {
            $this->accessor = $resource_or_accessor;
        }
    }

    /**
     * Create a relation definition for the given relation name.
     *
     * @param  string  $name
     * @param  string|null  $resource_or_accessor
     * @param  string|null  $alias
     * @return self
     */
    public static function to(string $name, ?string $resource_or_accessor = null, ?string $alias = null): self
    {
        return new self($name, $resource_or_accessor, $alias);
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
     * Specify the child fields to be considered when planning nested eager loads.
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
     * The callback receives the relation's query builder.
     *
     * @param  callable  $constraint
     * @return self
     */
    public function constrain(callable $constraint): self
    {
        $this->constraint = $constraint;

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
                'relation'     => $this->name,
                'resource'     => $this->resource,
                'accessor'     => $this->accessor,
                'extras'       => $this->extras ?: null,
                'fields'       => $this->fields,
                'constraint'   => $this->constraint,
                'guards'       => $this->getGuards() ?: null,
                'transformers' => $this->getTransformers() ?: null,
            ], static fn ($value) => $value !== null)
        ];
    }
}
