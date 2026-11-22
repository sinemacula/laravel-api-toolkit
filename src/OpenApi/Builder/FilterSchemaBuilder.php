<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Enums\ColumnValueKind;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaNameCollisionGuard;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Support\EnumCast;

/**
 * Builds one components.schemas entry per query surface, typing the filter
 * documents its resource accepts.
 *
 * Values are typed as the request-time guard checks them, from the column's
 * enum cast or storage type, in the canonical form of each.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FilterSchemaBuilder
{
    /** The path prefix under which component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** The suffix naming a resource's filter document component */
    private const string NAME_SUFFIX = 'Filters';

    /** The token the bare value shorthand compiles to */
    private const string EQUALITY = '$eq';

    /** @var array<string, mixed> Any scalar, for a value whose type is unknown */
    private const array SCALAR = ['type' => ['string', 'number', 'boolean']];

    /** The largest magnitude a single-precision column stores */
    private const float SINGLE_MAX = 3.4028234663852886E+38;

    /** The description of a key only some callers may use */
    private const string CONDITIONAL = 'Refused, as an undeclared key is, for callers the resource does not admit.';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral  $literal
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $enums
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ScopeSchemaBuilder  $scopes
     */
    public function __construct(

        /** The catalogue providing each resource's query surface. */
        private MetadataCatalogue $catalogue,

        /** The reader of each filtered column's storage type. */
        private ColumnLiteral $literal,

        /** The registry collecting the enums a filter value references. */
        private EnumSchemaRegistry $enums,

        /** The builder of each named scope's argument schema. */
        private ScopeSchemaBuilder $scopes,
    ) {}

    /**
     * Name the filter document component of the given resource.
     *
     * @param  class-string  $resourceClass
     * @return string
     */
    public static function nameOf(string $resourceClass): string
    {
        return SchemaComponentName::fromResource($resourceClass) . self::NAME_SUFFIX;
    }

    /**
     * Build one filter document schema per query surface, claiming each name
     * against those already claimed.
     *
     * @param  array<string, string>  $claimed
     * @return array<string, array<string, mixed>>
     */
    public function build(array &$claimed): array
    {
        $guard   = new SchemaNameCollisionGuard;
        $items   = $this->catalogue->getQueryLimits()[QueryCostLimits::MAX_IN_ITEMS] ?? 0;
        $schemas = [];

        foreach ($this->catalogue->getQuerySurfaces() as $surface) {

            $name = self::nameOf($surface->resource);

            $guard->claim($claimed, $name, $surface->resource);

            $schemas[$name] = $this->buildDocument($surface, $items);
        }

        return $schemas;
    }

    /**
     * Build the schema of a filter document on the given surface.
     *
     * A relation shadows a column of the same name, as the filter routes it.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $surface
     * @param  int  $items
     * @return array<string, mixed>
     */
    private function buildDocument(QuerySurfaceDescriptor $surface, int $items): array
    {
        $properties = [];

        foreach ($surface->columns as $column) {

            if ($column->capability === null) {
                continue;
            }

            $properties[$column->column] = $this->describe($this->buildColumn($column, $surface->models, $items), $column->filterConditional);
        }

        $relations  = $this->buildRelations($surface->relations);
        $properties = [...$properties, ...$relations];

        if ($surface->isFilterable()) {
            $properties['$and'] = $this->buildGroup($surface->resource);
            $properties['$or']  = $properties['$and'];
        }

        if ($relations !== []) {
            $properties['$has']   = $this->buildNamed(array_keys($relations), $relations);
            $properties['$hasnt'] = $properties['$has'];
        }

        if ($surface->scopes !== []) {
            $properties['$scope'] = $this->buildScopes($surface);
        }

        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    /**
     * Build the schema of a column's value: the shorthand where it answers
     * equality, or an object of the operators it answers.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor  $column
     * @param  list<class-string>  $models
     * @param  int  $items
     * @return array<string, mixed>
     */
    private function buildColumn(QueryColumnDescriptor $column, array $models, int $items): array
    {
        $value     = $this->buildValue($column->column, $models);
        $operators = [];

        foreach ($column->operators as $token) {

            $operand = $this->buildOperand($this->catalogue->getOperatorShape($token), $value, $items);

            if ($operand === null) {
                continue;
            }

            $operators[$token] = $operand;
        }

        $object = ['type' => 'object', 'properties' => $operators, 'minProperties' => 1, 'additionalProperties' => false];

        return in_array(self::EQUALITY, $column->operators, true) ? ['anyOf' => [$value, $object]] : $object;
    }

    /**
     * Build the schema of an operator's value from its declared shape, or null
     * when the item cap refuses every value of the shape.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FilterValueShape|null  $shape
     * @param  array<string, mixed>  $value
     * @param  int  $items
     * @return array<string, mixed>|null
     */
    private function buildOperand(?FilterValueShape $shape, array $value, int $items): ?array
    {
        $capped = $items > 0 ? ['maxItems' => $items] : [];

        return match ($shape) {
            FilterValueShape::SCALAR   => $value,
            FilterValueShape::LIST     => ['type' => 'array', 'items' => $value, ...$capped],
            FilterValueShape::BOUNDS   => $items === 1 ? null : ['type' => 'array', 'items' => $value, 'minItems' => 2, 'maxItems' => 2],
            FilterValueShape::PRESENCE => ['const' => true],
            FilterValueShape::ELEMENTS => ['anyOf' => [self::SCALAR, ['type' => 'array', 'items' => self::SCALAR, 'minItems' => 1, ...$capped]]],
            null                       => [],
        };
    }

    /**
     * Build the schema of a column's value on every model the surface filters,
     * or any scalar where they differ.
     *
     * @param  string  $column
     * @param  list<class-string>  $models
     * @return array<string, mixed>
     */
    private function buildValue(string $column, array $models): array
    {
        $value = null;

        foreach ($models as $modelClass) {

            $schema = is_subclass_of($modelClass, Model::class) ? $this->buildValueOn(new $modelClass, $column) : self::SCALAR;

            if ($value !== null && $schema !== $value) {
                return self::SCALAR;
            }

            $value = $schema;
        }

        return $value ?? self::SCALAR;
    }

    /**
     * Build the schema of the canonical value of a model's column.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @return array<string, mixed>
     */
    private function buildValueOn(Model $model, string $column): array
    {
        $enum = EnumCast::enumOf($model->getCasts()[$column] ?? null);

        if ($enum !== null) {
            return ['$ref' => $this->enums->reference($enum)];
        }

        return $this->buildStored($this->literal->typeOf($model, $column));
    }

    /**
     * Build the schema of the canonical value a column of the catalogue type
     * compares.
     *
     * @param  string|null  $type
     * @return array<string, mixed>
     */
    private function buildStored(?string $type): array
    {
        return match (ColumnValueKind::of($type)) {
            ColumnValueKind::INTEGER   => $this->buildInteger((string) $type),
            ColumnValueKind::NUMBER    => $this->buildNumber((string) $type),
            ColumnValueKind::BOOLEAN   => ['type' => 'boolean'],
            ColumnValueKind::DATE      => ['type' => 'string', 'format' => 'date'],
            ColumnValueKind::DATETIME  => ['type' => 'string', 'pattern' => ColumnLiteral::DATE_TIME_PATTERN],
            ColumnValueKind::UUID      => ['type' => 'string', 'format' => 'uuid'],
            ColumnValueKind::TEXT      => ['type' => 'string'],
            ColumnValueKind::UNCHECKED => self::SCALAR,
        };
    }

    /**
     * Build the schema of a value an integer column of the catalogue type
     * holds, 64-bit unless the type is narrower.
     *
     * @param  string  $type
     * @return array<string, mixed>
     */
    private function buildInteger(string $type): array
    {
        [$minimum, $maximum] = ColumnLiteral::INTEGER_BOUNDS[$type] ?? [PHP_INT_MIN, PHP_INT_MAX];

        return ['type' => 'integer', 'minimum' => $minimum, 'maximum' => $maximum];
    }

    /**
     * Build the schema of a value a numeric column of the catalogue type holds,
     * single-precision for a single-precision type.
     *
     * @param  string  $type
     * @return array<string, mixed>
     */
    private function buildNumber(string $type): array
    {
        return $type === 'float4'
            ? ['type' => 'number', 'format' => 'float', 'minimum' => -self::SINGLE_MAX, 'maximum' => self::SINGLE_MAX]
            : ['type' => 'number'];
    }

    /**
     * Build the schema of each relation's body, keyed by relation name.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor>  $relations
     * @return array<string, array<string, mixed>>
     */
    private function buildRelations(array $relations): array
    {
        $bodies = [];

        foreach ($relations as $relation) {
            $bodies[$relation->relation] = $this->describe($this->buildRelation($relation), $relation->conditional);
        }

        return $bodies;
    }

    /**
     * Build the schema of a relation's body: the governing resource's filter
     * document, any object where each related type governs its own, or an empty
     * one where nothing governs it.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor  $relation
     * @return array<string, mixed>
     */
    private function buildRelation(QueryRelationDescriptor $relation): array
    {
        if ($relation->polymorphic) {
            return ['type' => 'object', 'description' => 'Checked against the filters of each related type.'];
        }

        return $relation->resource === null
            ? ['type' => 'object', 'maxProperties' => 0]
            : ['$ref' => self::SCHEMA_REF_PREFIX . self::nameOf($relation->resource)];
    }

    /**
     * Build the schema of a logical group: a non-empty list of non-empty
     * documents on the same surface.
     *
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    private function buildGroup(string $resourceClass): array
    {
        return [
            'type'     => 'array',
            'items'    => ['$ref' => self::SCHEMA_REF_PREFIX . self::nameOf($resourceClass), 'minProperties' => 1],
            'minItems' => 1,
        ];
    }

    /**
     * Build the schema of the named scopes a document may invoke: a name or a
     * list of names where every argument may be left out, or names keyed to
     * their arguments, each required one listed as such.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $surface
     * @return array<string, mixed>
     */
    private function buildScopes(QuerySurfaceDescriptor $surface): array
    {
        $described = $this->scopes->build(SchemaCompiler::compile($surface->resource));
        $arguments = [];
        $bare      = [];

        foreach ($surface->scopes as $scope) {

            /** @var array<string, mixed> $parameters */
            $parameters = $described[$scope->name]['parameters'] ?? [];

            /** @var list<string> $declared */
            $declared = $parameters['required'] ?? [];
            $required = array_values(array_unique([...$declared, ...array_keys(array_filter($scope->arguments))]));

            if ($required !== []) {
                $parameters['required'] = $required;
            }

            $arguments[$scope->name] = $this->describe($parameters, $scope->conditional);

            if ($required !== [] || array_key_exists('anyOf', $parameters)) {
                continue;
            }

            $bare[] = $scope->name;
        }

        return $this->buildNamed($bare, $arguments);
    }

    /**
     * Build the schema of a key taking a name, a non-empty list of names, or a
     * non-empty object of names keyed to their values.
     *
     * @param  list<string>  $names
     * @param  array<string, array<string, mixed>>  $values
     * @return array<string, mixed>
     */
    private function buildNamed(array $names, array $values): array
    {
        $keyed = ['type' => 'object', 'properties' => $values, 'minProperties' => 1, 'additionalProperties' => false];

        if ($names === []) {
            return $keyed;
        }

        $name = ['type' => 'string', 'enum' => $names];

        return ['anyOf' => [$name, ['type' => 'array', 'items' => $name, 'minItems' => 1], $keyed]];
    }

    /**
     * Describe a key only some callers may use, after anything already said of
     * it.
     *
     * @param  array<string, mixed>  $schema
     * @param  bool  $conditional
     * @return array<string, mixed>
     */
    private function describe(array $schema, bool $conditional): array
    {
        if (!$conditional) {
            return $schema;
        }

        $schema['description'] = is_string($schema['description'] ?? null) ? $schema['description'] . ' ' . self::CONDITIONAL : self::CONDITIONAL;

        return $schema;
    }
}
