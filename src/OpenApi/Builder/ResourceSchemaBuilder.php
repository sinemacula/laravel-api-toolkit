<?php

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Builds one components.schemas entry per registered resource.
 *
 * Walks the catalogue's resource map, compiles each resource schema, and emits
 * a named object schema whose properties are resolved field-by-field through
 * the correctness gate. Scalar fields take their resolved schema verbatim;
 * relations emit a conservative object-or-array reference to the related
 * component; count keys are non-negative integers. Guarded fields are emitted
 * as optional (omitted from the schema's required list), and undocumented
 * fields keep their permissive marker while remaining schema-valid.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ResourceSchemaBuilder
{
    /** The path prefix under which resource component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver  $resolver
     */
    public function __construct(
        private readonly MetadataCatalogue $catalogue,
        private readonly FieldTypeResolver $resolver,
    ) {}

    /**
     * Build the full components.schemas map, keyed by PascalCase schema name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function build(): array
    {
        $schemas = [];

        foreach ($this->catalogue->getResourceMap() as $modelClass => $resourceClass) {
            $schemas[$this->schemaName($resourceClass)] = $this->buildResourceSchema($resourceClass, $modelClass);
        }

        return $schemas;
    }

    /**
     * Build a single resource's object schema.
     *
     * @param  class-string  $resourceClass
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return array<string, mixed>
     */
    private function buildResourceSchema(string $resourceClass, string $modelClass): array
    {
        $compiled   = SchemaCompiler::compile($resourceClass);
        $properties = [];
        $required   = [];

        foreach ($compiled->getFieldKeys() as $fieldKey) {
            $field = $compiled->getField($fieldKey);

            if ($field === null) {
                continue;
            }

            $properties[$fieldKey] = $this->buildFieldProperty($fieldKey, $field, $modelClass);

            if (!$this->isRequired($field)) {
                continue;
            }

            $required[] = $fieldKey;
        }

        foreach (array_keys($compiled->getCountDefinitions()) as $presentKey) {

            // A count never overwrites an existing property: when a count's
            // present key collides with a relation/field of the same name, the
            // richer field shape already emitted is preserved.
            $properties[$presentKey] ??= $this->buildCountProperty();
        }

        return $this->wrapObjectSchema($properties, $required);
    }

    /**
     * Build the JSON Schema property for a single compiled field.
     *
     * Relations emit a conservative object-or-array reference shape; all other
     * fields are resolved through the correctness gate.
     *
     * @param  string  $fieldKey
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return array<string, mixed>
     */
    private function buildFieldProperty(string $fieldKey, CompiledFieldDefinition $field, string $modelClass): array
    {
        if ($field->relation !== null && $field->resource !== null) {
            return $this->buildRelationProperty($field->resource);
        }

        return $this->resolver->resolve($fieldKey, $field, $modelClass)->toArray();
    }

    /**
     * Build the conservative relation property: a reference to the related
     * component valid for a single object, an array, or null (cardinality is
     * unknowable without a model instance), flagged as unknown cardinality.
     *
     * Nullability is expressed with a JSON Schema 2020-12 `{"type": "null"}`
     * member rather than the OpenAPI 3.0 `nullable` keyword, which is an inert
     * unknown keyword under 3.1 / JSON Schema 2020-12.
     *
     * @param  class-string  $childResource
     * @return array<string, mixed>
     */
    private function buildRelationProperty(string $childResource): array
    {
        $ref = self::SCHEMA_REF_PREFIX . $this->schemaName($childResource);

        return [
            'oneOf' => [
                ['$ref' => $ref],
                ['type' => 'array', 'items' => ['$ref' => $ref]],
                ['type' => 'null'],
            ],
            'x-cardinality' => 'unknown',
        ];
    }

    /**
     * Build the count property: a non-negative integer.
     *
     * @return array<string, mixed>
     */
    private function buildCountProperty(): array
    {
        return ['type' => 'integer', 'minimum' => 0];
    }

    /**
     * Wrap a property map and its required keys into an object schema.
     *
     * The required list is omitted entirely when no field qualifies, keeping
     * the emitted schema minimal and valid.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function wrapObjectSchema(array $properties, array $required): array
    {
        $schema = [
            'type'       => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Determine whether a field is a required property of the schema.
     *
     * Relations and counts are never required, and guarded fields are
     * conditionally present so are always optional. Only a plain, non-guarded
     * scalar contributes to the required list.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return bool
     */
    private function isRequired(CompiledFieldDefinition $field): bool
    {
        return $field->relation === null && $field->guards === [];
    }

    /**
     * Derive the PascalCase component schema name from a resource class.
     *
     * The class basename has its trailing "Resource" suffix removed, so
     * UserResource becomes User.
     *
     * @param  class-string  $resourceClass
     * @return string
     */
    private function schemaName(string $resourceClass): string
    {
        $position = strrpos($resourceClass, '\\');
        $basename = $position === false ? $resourceClass : substr($resourceClass, $position + 1);

        return preg_replace('/Resource$/', '', $basename) ?? $basename;
    }
}
