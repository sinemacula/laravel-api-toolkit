<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Database\Eloquent\Model;
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
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Builds one components.schemas entry per registered resource.
 *
 * The query surface sits on each property, not a global parameter, so it is
 * pruned with its schema per audience.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResourceSchemaBuilder
{
    /** The property an API resource's schema pins to its resource type */
    public const string TYPE_PROPERTY = '_type';

    /** The property a resource's relation aggregates are rendered under */
    private const string AGGREGATES_PROPERTY = 'aggregates';

    /** The path prefix under which resource component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** The property extension naming what a field may be queried by */
    private const string QUERY_SURFACE_KEY = 'x-query-surface';

    /** The schema extension naming the relations a filter may descend through */
    private const string TRAVERSABLE_RELATIONS_KEY = 'x-traversable-relations';

    /** The property extension marking a shape the document does not describe */
    private const string UNDOCUMENTED_KEY = 'x-undocumented';

    /** The schema extension naming the fields returned when none are selected */
    private const string DEFAULT_FIELDS_KEY = 'x-default-fields';

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

        $resourceMap = $this->catalogue->getResourceMap();
        $built       = array_fill_keys(array_values($resourceMap), true);

        foreach ($resourceMap as $modelClass => $resourceClass) {

            $name = SchemaComponentName::fromResource($resourceClass);

            $guard->claim($claimed, $name, $resourceClass);

            $schemas[$name] = $this->buildResourceSchema($resourceClass, $modelClass, $built);
        }

        return $schemas;
    }

    /**
     * Build the map of component name to deriving resource class.
     *
     * Lets a caller reserve the resource names before merging other schemas in.
     *
     * @return array<string, string>
     */
    public function claims(): array
    {
        $claimed = [];
        $guard   = new SchemaNameCollisionGuard;

        foreach ($this->catalogue->getResourceMap() as $resourceClass) {
            $guard->claim($claimed, SchemaComponentName::fromResource($resourceClass), $resourceClass);
        }

        return $claimed;
    }

    /**
     * Build a single resource's object schema.
     *
     * @param  class-string  $resourceClass
     * @param  class-string  $modelClass
     * @param  array<string, true>  $built
     * @return array<string, mixed>
     */
    private function buildResourceSchema(string $resourceClass, string $modelClass, array $built): array
    {
        $compiled   = SchemaCompiler::compile($resourceClass);
        $properties = [];
        $limited    = [];

        foreach ($compiled->getFields() as $fieldKey => $field) {
            $property = $this->buildFieldProperty($fieldKey, $field, $modelClass, $built);

            // Capping precedes transforming, so overrides still truncate
            if ($this->isBoundedArray($property)) {
                $limited[] = $fieldKey;
            }

            $properties[$fieldKey] = $this->withQuerySurface($this->overrideProperty($field) ?? $property, $field, $compiled);
        }

        if ($compiled->getAggregateDefinitions() !== []) {
            $properties[self::AGGREGATES_PROPERTY] ??= $this->buildAggregatesProperty($compiled, $modelClass);
        }

        if ($limited !== []) {
            $properties[RelationLimit::TRUNCATED_KEY] = $this->buildTruncatedProperty($limited);
        }

        if (!is_a($resourceClass, ApiResourceInterface::class, true)) {
            return $this->wrapObjectSchema($properties, [], $compiled->getTraversableRelations());
        }

        $fixed    = $resourceClass::getFixedFields();
        $defaults = array_intersect([...$resourceClass::getDefaultFields(), ...$fixed], array_keys($properties));
        $required = array_filter($fixed, fn (string $key): bool => $this->isAlwaysPresent($compiled->getField($key)));

        $properties = [self::TYPE_PROPERTY => $this->buildTypeProperty($resourceClass)] + $properties;

        return [
            ...$this->wrapObjectSchema($properties, [self::TYPE_PROPERTY, ...$required], $compiled->getTraversableRelations()),
            self::DEFAULT_FIELDS_KEY => array_values(array_unique($defaults)),
        ];
    }

    /**
     * Build the JSON Schema property for a single compiled field.
     *
     * @param  string  $fieldKey
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  class-string  $modelClass
     * @param  array<string, true>  $built
     * @return array<string, mixed>
     */
    private function buildFieldProperty(string $fieldKey, CompiledFieldDefinition $field, string $modelClass, array $built): array
    {
        if ($field->relation !== null && $field->resource !== null) {
            return $this->buildRelationProperty($field, $field->relation, $field->resource, $modelClass, $built);
        }

        if (!is_subclass_of($modelClass, Model::class)) {
            return ($field->openApi ?? OpenApiFieldSchema::undocumented())->toArray();
        }

        return $this->resolver->resolve($fieldKey, $field, $modelClass)->toArray();
    }

    /**
     * Resolve the schema that replaces a field's inferred one, if any.
     *
     * A declaration wins, and a transformer changes the value, so an undeclared
     * one is undocumented.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return array<string, mixed>|null
     */
    private function overrideProperty(CompiledFieldDefinition $field): ?array
    {
        return match (true) {
            $field->openApi      !== null => $field->openApi->toArray(),
            $field->transformers !== []   => OpenApiFieldSchema::undocumented()->toArray(),
            default                       => null,
        };
    }

    /**
     * Build the relation property from its resolved Eloquent cardinality.
     *
     * An unmapped resource has no component, so it becomes an undocumented
     * object, never a dangling $ref. An unresolved relation may take any shape,
     * and a to-one relation renders null when the related model is absent,
     * unless it declares a default model.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $relation
     * @param  string  $childResource
     * @param  class-string  $modelClass
     * @param  array<string, true>  $built
     * @return array<string, mixed>
     *
     * @SuppressWarnings("php:S3011")
     */
    private function buildRelationProperty(CompiledFieldDefinition $field, string $relation, string $childResource, string $modelClass, array $built): array
    {
        $target = isset($built[$childResource]) && class_exists($childResource)
            ? ['$ref' => self::SCHEMA_REF_PREFIX . SchemaComponentName::fromResource($childResource)]
            : ['type' => 'object', self::UNDOCUMENTED_KEY => true];
        $limit    = RelationLimit::for($field);
        $array    = ['type' => 'array', 'items' => $target] + ($limit > 0 ? ['maxItems' => $limit] : []);
        $instance = $this->resolveRelation($relation, $modelClass);
        $null     = ['type' => 'null'];

        if ($instance === null) {
            return ['oneOf' => [$target, $array, $null], 'x-cardinality' => 'unknown'];
        }

        if (!$this->isToOne($instance)) {
            return $array;
        }

        $defaulted = (bool) (new \ReflectionProperty($instance, 'withDefault'))->getValue($instance);

        return $defaulted ? $target : ['oneOf' => [$target, $null]];
    }

    /**
     * Resolve the relation instance for the given method on the model.
     *
     * @param  string  $relation
     * @param  class-string  $modelClass
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    private function resolveRelation(string $relation, string $modelClass): ?Relation
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        return $this->introspector->resolveRelation($relation, new $modelClass);
    }

    /**
     * Determine whether the resolved relation is a to-one relation.
     *
     * Leaf classes are listed since each shares a base with a to-many class.
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
     * Determine whether the relation property may render as a bounded array.
     *
     * @param  array<string, mixed>  $property
     * @return bool
     */
    private function isBoundedArray(array $property): bool
    {
        $members = is_array($property['oneOf'] ?? null) ? $property['oneOf'] : [$property];

        foreach ($members as $member) {
            if (is_array($member) && isset($member['maxItems'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the optional property naming the relations a response truncated.
     *
     * @param  array<int, string>  $limited
     * @return array<string, mixed>
     */
    private function buildTruncatedProperty(array $limited): array
    {
        return [
            'type'        => 'array',
            'items'       => ['type' => 'string', 'enum' => $limited],
            'uniqueItems' => true,
            'minItems'    => 1,
        ];
    }

    /**
     * Build the aggregates property, keyed by each aggregate's present key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @param  class-string  $modelClass
     * @return array<string, mixed>
     */
    private function buildAggregatesProperty(CompiledSchema $compiled, string $modelClass): array
    {
        $properties = [];

        foreach ($compiled->getAggregateDefinitions() as $presentKey => $definition) {
            $properties[$presentKey] = $this->resolver->resolveAggregate($definition, $modelClass);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * Build the type-discriminator property fixed to the resource's type.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>  $resourceClass
     * @return array<string, mixed>
     */
    private function buildTypeProperty(string $resourceClass): array
    {
        return ['type' => 'string', 'const' => $resourceClass::getResourceType()];
    }

    /**
     * Wrap a property map, its required keys and traversable relations into an
     * object schema.
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
     * Attach the query surface a field declares to its emitted property.
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
     * Build what a single field may be filtered, ordered and searched by.
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
     * Describe what a filter may ask of the field's column.
     *
     * Read from the compiled column map the request-time gate reads, so the two
     * agree.
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
        $operators  = $this->catalogue->getOperatorsFor($capability);

        if ($operators === []) {
            return null;
        }

        $surface = [
            'key'        => $field->filterable,
            'capability' => $capability->value,
            'operators'  => $operators,
        ];

        if (array_key_exists($field->filterable, $compiled->getConditionalFilterKeys())) {
            $surface['conditional'] = true;
        }

        return $surface;
    }

    /**
     * Describe what an order may ask of the field's column.
     *
     * `indexed` is the resource's claim (no exemption recorded), not a database
     * reading.
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

        if ($field->unindexedReason !== null) {
            $surface['reason'] = $field->unindexedReason;
        }

        if (array_key_exists($field->sortable, $compiled->getConditionalSortKeys())) {
            $surface['conditional'] = true;
        }

        return $surface;
    }

    /**
     * Describe how a free-text search matches the field's column.
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
     * Determine whether a fixed field is present in every response.
     *
     * Only a plain column read is; a relation, compute, accessor, either guard
     * or transformer may leave it out.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition|null  $field
     * @return bool
     */
    private function isAlwaysPresent(?CompiledFieldDefinition $field): bool
    {
        return $field !== null
            && [$field->relation, $field->compute, $field->accessor]                 === [null, null, null]
            && [...$field->guards, ...$field->callerGuards, ...$field->transformers] === [];
    }
}
