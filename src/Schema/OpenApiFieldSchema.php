<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Immutable value object holding one field's resolved OpenAPI contract.
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
     * @param  list<mixed>|null  $examples
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

        /** Example values for documentation */
        public ?array $examples = null,

        /** Human-readable description of the field */
        public ?string $description = null,

        /** Whether the field's type could not be resolved and is flagged */
        public bool $undocumented = false,

        /** Reference to a named component schema, e.g. an enum's component */
        public ?string $ref = null,
    ) {}

    /**
     * Create a schema referencing a named component.
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
     * @param  string|null  $description
     * @return self
     */
    public static function undocumented(?string $description = null): self
    {
        return new self(description: $description, undocumented: true);
    }

    /**
     * Return a copy of this schema that does not admit null.
     *
     * @return self
     */
    public function withoutNull(): self
    {
        return new self(
            type        : $this->type,
            format      : $this->format,
            enum        : $this->enum,
            examples    : $this->examples,
            description : $this->description,
            undocumented: $this->undocumented,
            ref         : $this->ref,
        );
    }

    /**
     * Determine whether this schema only annotates a field, declaring no shape,
     * so the field keeps the schema inferred for it.
     *
     * @return bool
     */
    public function annotatesOnly(): bool
    {
        return [$this->type, $this->format, $this->enum, $this->ref] === [null, null, null, null]
            && !$this->nullable
            && !$this->undocumented;
    }

    /**
     * Lay this schema's examples and description over a schema fragment.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function annotate(array $schema): array
    {
        return [...$schema, ...$this->annotations()];
    }

    /**
     * Convert this schema to a JSON Schema 2020-12 fragment.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->ref !== null) {
            return $this->annotate($this->referenceSchema());
        }

        if ($this->undocumented) {
            return $this->annotate(['x-undocumented' => true]);
        }

        return $this->annotate(array_filter([
            'type'   => $this->resolveType(),
            'format' => $this->format,
            'enum'   => $this->enum,
        ], static fn ($value) => $value !== null));
    }

    /**
     * Collect the declared examples and description.
     *
     * @return array<string, mixed>
     */
    private function annotations(): array
    {
        return array_filter([
            'examples'    => $this->examples,
            'description' => $this->description,
        ], static fn ($value) => $value !== null);
    }

    /**
     * Convert this schema to a component reference.
     *
     * A bare $ref cannot carry the null type, so a nullable one uses anyOf.
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
     * Resolve the `type` keyword, using the 2020-12 type-array form for null.
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
