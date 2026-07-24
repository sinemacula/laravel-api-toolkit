<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
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
 * relations emit a single reference or an array of references according to
 * their resolved Eloquent cardinality, falling back to a conservative
 * object-or-array reference only when the relation cannot be resolved; count
 * keys are non-negative integers. Every schema leads with a required `_type`
 * discriminator fixed to the resource's registered type, mirroring the value
 * stamped on each runtime item. Guarded fields are emitted as optional (omitted
 * from the schema's required list), and undocumented fields keep their
 * permissive marker while remaining schema-valid.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ResourceSchemaBuilder
{
    /** The path prefix under which resource component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver  $resolver
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     */
    public function __construct(

        /** The metadata catalogue describing resource schemas. */
        private readonly MetadataCatalogue $catalogue,

        /** The resolver mapping resource fields to OpenAPI types. */
        private readonly FieldTypeResolver $resolver,

        /** The provider used to resolve relation cardinality. */
        private readonly SchemaIntrospectionProvider $introspector,
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

        if (is_a($resourceClass, ApiResourceInterface::class, true)) {

            // Every resource stamps its type discriminator on each item at
            // runtime, so the schema leads with a constant meta key mirroring
            // that value and always requires it.
            $properties = ['_type' => $this->buildTypeProperty($resourceClass)] + $properties;
            $required   = ['_type', ...$required];
        }

        return $this->wrapObjectSchema($properties, $required);
    }

    /**
     * Build the JSON Schema property for a single compiled field.
     *
     * Relations emit a reference shape derived from their cardinality; all
     * other fields are resolved through the correctness gate.
     *
     * @param  string  $fieldKey
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return array<string, mixed>
     */
    private function buildFieldProperty(string $fieldKey, CompiledFieldDefinition $field, string $modelClass): array
    {
        if ($field->relation !== null && $field->resource !== null) {
            return $this->buildRelationProperty($field->relation, $field->resource, $modelClass);
        }

        return $this->resolver->resolve($fieldKey, $field, $modelClass)->toArray();
    }

    /**
     * Build the relation property from its resolved Eloquent cardinality.
     *
     * A to-one relation emits a single reference to the related component; a
     * to-many relation emits an array of references. When the relation cannot
     * be resolved (an unbound polymorphic relation, or one that throws), it
     * falls back to a conservative object-or-array-or-null reference flagged
     * with an unknown cardinality.
     *
     * @param  string  $relation
     * @param  class-string  $childResource
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return array<string, mixed>
     */
    private function buildRelationProperty(string $relation, string $childResource, string $modelClass): array
    {
        $ref      = self::SCHEMA_REF_PREFIX . $this->schemaName($childResource);
        $instance = $this->resolveRelation($relation, $modelClass);

        if ($instance === null) {
            return $this->conservativeRelationProperty($ref);
        }

        return $this->isToOne($instance)
            ? ['$ref' => $ref]
            : ['type' => 'array', 'items' => ['$ref' => $ref]];
    }

    /**
     * Resolve the relation instance for the given method on the model, or null
     * when the model class is not instantiable as an Eloquent model or the
     * relation cannot be resolved.
     *
     * @param  string  $relation
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    private function resolveRelation(string $relation, string $modelClass): ?Relation
    {
        if (!class_exists($modelClass)) {
            return null;
        }

        return $this->introspector->resolveRelation($relation, new $modelClass);
    }

    /**
     * Determine whether the resolved relation is a to-one relation.
     *
     * The to-one leaf classes are listed explicitly to avoid the inheritance
     * trap where HasOneThrough extends HasManyThrough: a broader instanceof
     * check would misclassify to-many relations as to-one.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @return bool
     */
    private function isToOne(Relation $relation): bool
    {
        return match (true) {
            $relation instanceof HasOne        => true,
            $relation instanceof MorphOne      => true,
            $relation instanceof BelongsTo     => true,
            $relation instanceof HasOneThrough => true,
            default                            => false,
        };
    }

    /**
     * Build the conservative relation property: a reference to the related
     * component valid for a single object, an array, or null, flagged as
     * unknown cardinality.
     *
     * Nullability is expressed with a JSON Schema 2020-12 `{"type": "null"}`
     * member rather than the OpenAPI 3.0 `nullable` keyword, which is an inert
     * unknown keyword under 3.1 / JSON Schema 2020-12.
     *
     * @param  string  $ref
     * @return array<string, mixed>
     */
    private function conservativeRelationProperty(string $ref): array
    {
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
     * Build the type-discriminator property: a string fixed to the resource's
     * registered type, mirroring the value stamped on every runtime item.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>  $resourceClass
     * @return array<string, mixed>
     */
    private function buildTypeProperty(string $resourceClass): array
    {
        return ['type' => 'string', 'const' => $resourceClass::getResourceType()];
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
