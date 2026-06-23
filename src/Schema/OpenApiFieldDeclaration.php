<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Fluent carrier for an author-declared OpenAPI field contract.
 *
 * Returned by BaseDefinition::openapi(), it collects the declared type,
 * format, nullability, enumerated values, example, and description through
 * chainable setters, then freezes into an immutable OpenApiFieldSchema. The
 * carrier is additive: it exists only when openapi() is called and is omitted
 * from the definition's toArray() output otherwise, preserving byte-for-byte
 * backward compatibility.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OpenApiFieldDeclaration
{
    /** @var string|null Declared JSON Schema type */
    private ?string $type = null;

    /** @var string|null Declared JSON Schema format */
    private ?string $format = null;

    /** @var bool Whether the field admits null */
    private bool $nullable = false;

    /** @var array<int, scalar>|null Declared enumerated values */
    private ?array $enum = null;

    /** @var mixed Declared example value */
    private mixed $example = null;

    /** @var string|null Declared description */
    private ?string $description = null;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Schema\BaseDefinition  $parent
     */
    public function __construct(

        /** The owning definition, returned by end() to continue chaining */
        private readonly BaseDefinition $parent,

    ) {}

    /**
     * Declare the field's JSON Schema type.
     *
     * @param  string  $type
     * @return self
     */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Declare the field's JSON Schema format.
     *
     * @param  string  $format
     * @return self
     */
    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Declare whether the field admits null.
     *
     * @param  bool  $nullable
     * @return self
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Declare the field's enumerated set of permitted values.
     *
     * @param  array<int, scalar>  $values
     * @return self
     */
    public function enum(array $values): self
    {
        $this->enum = $values;

        return $this;
    }

    /**
     * Declare an example value for the field.
     *
     * @param  mixed  $example
     * @return self
     */
    public function example(mixed $example): self
    {
        $this->example = $example;

        return $this;
    }

    /**
     * Declare a human-readable description for the field.
     *
     * @param  string  $description
     * @return self
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Return to the owning definition to continue the schema chain.
     *
     * @return \SineMacula\ApiToolkit\Schema\BaseDefinition
     */
    public function end(): BaseDefinition
    {
        return $this->parent;
    }

    /**
     * Freeze the declared values into an immutable resolved schema.
     *
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    public function toSchema(): OpenApiFieldSchema
    {
        return new OpenApiFieldSchema(
            type       : $this->type,
            format     : $this->format,
            nullable   : $this->nullable,
            enum       : $this->enum,
            example    : $this->example,
            description: $this->description,
        );
    }
}
