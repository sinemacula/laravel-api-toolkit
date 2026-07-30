<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Immutable value object holding one field's resolved OpenAPI contract.
 *
 * Carries the per-field type, format, nullability, enumerated values, example,
 * and description, plus an explicit undocumented flag for fields whose type
 * could not be resolved and an optional reference to a named component for an
 * enum-typed field. Converts to a JSON Schema 2020-12 fragment suitable for
 * embedding in an OpenAPI 3.1 components document. Read only by the emission
 * path; never consulted during runtime serialization.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class OpenApiFieldSchema
{
    /**
     * Constructor.
     *
     * @param  string|null  $type
     * @param  string|null  $format
     * @param  bool  $nullable
     * @param  array<int, scalar>|null  $enum
     * @param  mixed  $example
     * @param  string|null  $description
     * @param  bool  $undocumented
     * @param  string|null  $ref
     */
    public function __construct(

        /** JSON Schema type, e.g. 'string', 'integer', 'boolean' */
        public ?string $type = null,

        /** JSON Schema format hint, e.g. 'date-time', 'uuid' */
        public ?string $format = null,

        /** Whether the field admits null */
        public bool $nullable = false,

        /** Enumerated set of permitted scalar values */
        public ?array $enum = null,

        /** Example value for documentation */
        public mixed $example = null,

        /** Human-readable description of the field */
        public ?string $description = null,

        /** Whether the field's type could not be resolved and is flagged */
        public bool $undocumented = false,

        /** Reference to a named component schema, e.g. an enum's component */
        public ?string $ref = null,
    ) {}

    /**
     * Create a schema referencing a named component, widening to admit null
     * when the field is nullable.
     *
     * @param  string  $ref
     * @param  bool  $nullable
     * @return self
     */
    public static function reference(string $ref, bool $nullable = false): self
    {
        return new self(nullable: $nullable, ref: $ref);
    }

    /**
     * Create a permissive schema explicitly flagged as undocumented.
     *
     * The fragment admits any value (no `type` key), so it is permissive yet
     * schema-valid, and carries the `x-undocumented` extension marker.
     *
     * @param  string|null  $description
     * @return self
     */
    public static function undocumented(?string $description = null): self
    {
        return new self(description: $description, undocumented: true);
    }

    /**
     * Convert this schema to a JSON Schema 2020-12 fragment.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->ref !== null) {
            return $this->referenceSchema();
        }

        if ($this->undocumented) {
            return array_filter([
                'x-undocumented' => true,
                'description'    => $this->description,
            ], static fn ($value) => $value !== null);
        }

        return array_filter([
            'type'        => $this->resolveType(),
            'format'      => $this->format,
            'enum'        => $this->enum,
            'example'     => $this->example,
            'description' => $this->description,
        ], static fn ($value) => $value !== null);
    }

    /**
     * Convert this schema to a component reference, wrapping the reference in
     * an anyOf with a null member when the field admits null, since a bare $ref
     * cannot itself carry the null type.
     *
     * @return array<string, mixed>
     */
    private function referenceSchema(): array
    {
        $reference = ['$ref' => $this->ref];

        return $this->nullable
            ? ['anyOf' => [$reference, ['type' => 'null']]]
            : $reference;
    }

    /**
     * Resolve the JSON Schema `type` keyword, applying the 2020-12 nullable
     * type-array form when the field admits null.
     *
     * @return array<int, string>|string|null
     */
    private function resolveType(): array|string|null
    {
        if ($this->type === null) {
            return null;
        }

        return $this->nullable ? [$this->type, 'null'] : $this->type;
    }
}
