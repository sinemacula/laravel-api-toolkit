<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Resolves a single compiled field to its emitted OpenAPI schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FieldTypeResolver
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper  $mapper
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $enums
     */
    public function __construct(

        /** The provider used to introspect schema metadata. */
        private SchemaIntrospectionProvider $introspector,

        /** The mapper that converts column types to schema types. */
        private ColumnTypeMapper $mapper,

        /** The registry collecting enum classes referenced by a $ref. */
        private EnumSchemaRegistry $enums = new EnumSchemaRegistry,
    ) {}

    /**
     * Resolve one field's emitted schema by precedence: declared, flagged when
     * opaque, then inferred.
     *
     * @param  string  $fieldKey
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    public function resolve(string $fieldKey, CompiledFieldDefinition $field, string $modelClass): OpenApiFieldSchema
    {
        if ($field->openApi !== null) {
            return $field->openApi;
        }

        if ($this->isOpaque($field)) {
            return OpenApiFieldSchema::undocumented();
        }

        return $this->inferScalar($fieldKey, $modelClass);
    }

    /**
     * Resolve one relation aggregate's emitted schema by precedence: declared,
     * flagged when transformed, then inferred from its function.
     *
     * A minimum or maximum takes the related column's type, and is null over
     * zero related rows.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $definition
     * @param  class-string  $modelClass
     * @return array<string, mixed>
     */
    public function resolveAggregate(CompiledAggregateDefinition $definition, string $modelClass): array
    {
        if ($definition->openApi !== null) {
            return $definition->openApi->toArray();
        }

        if ($definition->transformers !== []) {
            return OpenApiFieldSchema::undocumented()->toArray();
        }

        return match ($definition->function) {
            AggregateFunction::COUNT                       => ['type' => 'integer', 'minimum' => 0],
            AggregateFunction::EXISTS                      => ['type' => 'boolean'],
            AggregateFunction::SUM, AggregateFunction::AVG => ['type' => ['number', 'null']],
            AggregateFunction::MIN, AggregateFunction::MAX => $this->resolveExtreme($definition, $modelClass)->toArray(),
        };
    }

    /**
     * Resolve a column's schema, reusing the declaration of a field that emits
     * the column unchanged.
     *
     * A guard only hides the value, so a guarded field's declaration is reused;
     * a model accessor changes it, so an accessed column's declaration is not.
     *
     * @param  string  $column
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition|null  $field
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    public function resolveColumn(string $column, string $modelClass, ?CompiledFieldDefinition $field = null): OpenApiFieldSchema
    {
        if ($field?->openApi !== null && $this->emitsColumn($field, $column, $modelClass)) {
            return $field->openApi;
        }

        return $this->inferScalar($column, $modelClass);
    }

    /**
     * Resolve a minimum or maximum from the related column, as nullable.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $definition
     * @param  class-string  $modelClass
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    private function resolveExtreme(CompiledAggregateDefinition $definition, string $modelClass): OpenApiFieldSchema
    {
        $relation = is_subclass_of($modelClass, Model::class)
            ? $this->introspector->resolveRelation($definition->relation, new $modelClass)
            : null;

        if ($relation === null || $relation instanceof MorphTo || $definition->column === null) {
            return OpenApiFieldSchema::undocumented();
        }

        $schema = $this->inferScalar($definition->column, $relation->getRelated()::class);

        return match (true) {
            $schema->undocumented => $schema,
            $schema->ref !== null => OpenApiFieldSchema::reference($schema->ref, true),
            default               => new OpenApiFieldSchema(type: $schema->type, format: $schema->format, nullable: true),
        };
    }

    /**
     * Determine whether a field emits its column's stored value.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $column
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return bool
     */
    private function emitsColumn(CompiledFieldDefinition $field, string $column, string $modelClass): bool
    {
        return !$this->hasAlternateSource($field)
            && $field->transformers === []
            && !(new $modelClass)->hasAnyGetMutator($column);
    }

    /**
     * Determine whether a field's value cannot be inferred from its column.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return bool
     */
    private function isOpaque(CompiledFieldDefinition $field): bool
    {
        return $this->hasAlternateSource($field) || $this->hasTransformers($field);
    }

    /**
     * Determine whether a field draws its value from somewhere other than its
     * column.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return bool
     */
    private function hasAlternateSource(CompiledFieldDefinition $field): bool
    {
        return $field->accessor !== null
            || $field->compute  !== null
            || $field->relation !== null;
    }

    /**
     * Determine whether a transformer intervenes between the column and the
     * emitted value.
     *
     * A guard decides only whether the value is present, never its type.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return bool
     */
    private function hasTransformers(CompiledFieldDefinition $field): bool
    {
        return $field->transformers !== [];
    }

    /**
     * Infer a plain scalar field's schema from its backing column and model
     * cast.
     *
     * @param  string  $fieldKey
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    private function inferScalar(string $fieldKey, string $modelClass): OpenApiFieldSchema
    {
        $model  = new $modelClass;
        $column = $this->introspector->getColumnDefinitions($model)[$fieldKey] ?? null;
        $cast   = $this->resolveCast($model, $fieldKey);

        if ($cast !== null && enum_exists($cast)) {
            return OpenApiFieldSchema::reference($this->enums->reference($cast), $column !== null && $column->nullable);
        }

        if ($column === null && $cast === null) {
            return OpenApiFieldSchema::undocumented();
        }

        return $this->mapper->map($column, $cast);
    }

    /**
     * Resolve the cast Eloquent applies to the given attribute, reading an
     * uncast date attribute, such as a timestamp, as a datetime cast.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $fieldKey
     * @return string|null
     */
    private function resolveCast(Model $model, string $fieldKey): ?string
    {
        $cast = $model->getCasts()[$fieldKey] ?? null;

        return match (true) {
            is_string($cast)                              => $cast,
            in_array($fieldKey, $model->getDates(), true) => 'datetime',
            default                                       => null,
        };
    }
}
