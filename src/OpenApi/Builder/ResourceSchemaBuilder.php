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
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaNameCollisionGuard;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
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
 * Each property also carries the query surface its field declares, read from
 * the same compiled schema the request-time gates read: the key a filter or an
 * order names the column by, the capability it was declared filterable with and
 * the operator tokens that capability answers, whether an index backs the sort
 * and the reason recorded where none does, and the strategy a free-text search
 * matches it by. A declaration the gates do not hold is never documented, so
 * the document cannot claim a column the request would reject.
 *
 * The surface is emitted per property rather than as a schema-level list or a
 * shared parameter component. A parameter component is global to the document,
 * so a per-resource surface placed there would carry one audience's columns
 * into every other audience's document; a property travels with the schema its
 * audience reaches and is dropped along with it. The relations a filter may
 * descend through are named on the schema itself rather than on a property,
 * since the grammar accepts the relation name while an aliased relation is
 * exposed under a key it does not accept.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResourceSchemaBuilder
{
    /** The path prefix under which resource component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** The property extension naming what a field may be queried by */
    private const string QUERY_SURFACE_KEY = 'x-query-surface';

    /** The schema extension naming the relations a filter may descend through */
    private const string TRAVERSABLE_RELATIONS_KEY = 'x-traversable-relations';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver  $resolver
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     */
    public function __construct(

        /** The metadata catalogue describing resource schemas. */
        private MetadataCatalogue $catalogue,

        /** The resolver mapping resource fields to OpenAPI types. */
        private FieldTypeResolver $resolver,

        /** The provider used to resolve relation cardinality. */
        private SchemaIntrospectionProvider $introspector,
    ) {}

    /**
     * Build the full components.schemas map, keyed by PascalCase schema name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function build(): array
    {
        $schemas = [];
        $claimed = [];
        $guard   = new SchemaNameCollisionGuard;

        foreach ($this->catalogue->getResourceMap() as $modelClass => $resourceClass) {
            $name = $this->schemaName($resourceClass);

            $guard->claim($claimed, $name, $resourceClass);

            $schemas[$name] = $this->buildResourceSchema($resourceClass, $modelClass);
        }

        return $schemas;
    }

    /**
     * Build the map of component name to deriving resource class, failing loud
     * when two distinct resources derive one name, so a caller can reserve the
     * resource names before merging other schemas into the same map.
     *
     * @return array<string, string>
     */
    public function claims(): array
    {
        $claimed = [];
        $guard   = new SchemaNameCollisionGuard;

        foreach ($this->catalogue->getResourceMap() as $resourceClass) {
            $guard->claim($claimed, $this->schemaName($resourceClass), $resourceClass);
        }

        return $claimed;
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

            $properties[$fieldKey] = $this->withQuerySurface(
                $this->buildFieldProperty($fieldKey, $field, $modelClass),
                $field,
                $compiled,
            );

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

        return $this->wrapObjectSchema($properties, $required, $compiled->getTraversableRelations());
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
     * Wrap a property map, its required keys, and the relations a filter may
     * descend through into an object schema.
     *
     * The required list is omitted entirely when no field qualifies, keeping
     * the emitted schema minimal and valid, and the traversable relations the
     * same way: a resource declaring none says so by carrying no such key.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<int, string>  $required
     * @param  array<int, string>  $relations
     * @return array<string, mixed>
     */
    private function wrapObjectSchema(array $properties, array $required, array $relations): array
    {
        $schema = [
            'type'       => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        if ($relations !== []) {
            $schema[self::TRAVERSABLE_RELATIONS_KEY] = $relations;
        }

        return $schema;
    }

    /**
     * Attach the query surface a field declares to its emitted property,
     * leaving a property whose field declares none untouched.
     *
     * @param  array<string, mixed>  $property
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return array<string, mixed>
     */
    private function withQuerySurface(array $property, CompiledFieldDefinition $field, CompiledSchema $compiled): array
    {
        $surface = $this->querySurface($field, $compiled);

        if ($surface === []) {
            return $property;
        }

        return [...$property, self::QUERY_SURFACE_KEY => $surface];
    }

    /**
     * Build the query surface for a single field: what it may be filtered,
     * ordered, and searched by.
     *
     * Each part is omitted where the field declares nothing, so a surface that
     * comes back empty means the field answers no query parameter at all.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return array<string, array<string, mixed>>
     */
    private function querySurface(CompiledFieldDefinition $field, CompiledSchema $compiled): array
    {
        $surface = [
            'filter' => $this->filterSurface($field, $compiled),
            'sort'   => $this->sortSurface($field, $compiled),
            'search' => $this->searchSurface($field, $compiled),
        ];

        return array_filter($surface, static fn (?array $part): bool => $part !== null);
    }

    /**
     * Describe what a filter may ask of the field's column: the key it is named
     * by, the capability it was declared with, and the operator tokens that
     * capability answers.
     *
     * The capability is read from the compiled column map the request-time gate
     * reads rather than from the field, so the operators the document lists are
     * the operators the gate accepts. A column the map does not carry is not
     * filterable however the field is declared, and is documented as such.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return array<string, mixed>|null
     */
    private function filterSurface(CompiledFieldDefinition $field, CompiledSchema $compiled): ?array
    {
        $columns = $compiled->getFilterableColumns();

        if ($field->filterable === null || !array_key_exists($field->filterable, $columns)) {
            return null;
        }

        $capability = $columns[$field->filterable];

        return [
            'key'        => $field->filterable,
            'capability' => $capability->value,
            'operators'  => $capability->permittedOperators(),
        ];
    }

    /**
     * Describe what an order may ask of the field's column: the key it is named
     * by and whether an index holds the order.
     *
     * A sortable declaration only survives validation where an ordered index
     * leads with the column or the resource exempted it, so an exemption is the
     * one thing that leaves the sort unindexed, and its recorded reason travels
     * with it. The index behind a backed sort is not named: which index serves
     * a column is a property of the database rather than of the API.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return array<string, mixed>|null
     */
    private function sortSurface(CompiledFieldDefinition $field, CompiledSchema $compiled): ?array
    {
        if ($field->sortable === null || !in_array($field->sortable, $compiled->getSortableColumns(), true)) {
            return null;
        }

        $surface = [
            'key'     => $field->sortable,
            'indexed' => $field->unindexedReason === null,
        ];

        if ($field->unindexedReason === null) {
            return $surface;
        }

        return [...$surface, 'reason' => $field->unindexedReason];
    }

    /**
     * Describe how a free-text search matches the field's column: the key it is
     * matched on and the strategy it is matched by.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return array<string, mixed>|null
     */
    private function searchSurface(CompiledFieldDefinition $field, CompiledSchema $compiled): ?array
    {
        $columns = $compiled->getSearchableColumns();

        if ($field->searchable === null || !array_key_exists($field->searchable, $columns)) {
            return null;
        }

        return ['key' => $field->searchable, 'strategy' => $columns[$field->searchable]->value];
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
     * @param  class-string  $resourceClass
     * @return string
     */
    private function schemaName(string $resourceClass): string
    {
        return SchemaComponentName::fromResource($resourceClass);
    }
}
