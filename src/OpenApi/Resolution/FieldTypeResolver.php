<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Resolves a single compiled field to its emitted OpenAPI schema.
 *
 * Applies the correctness gate's strict precedence -- declared wins, otherwise
 * a plain scalar backed by a real column is inferred from its column type and
 * model cast, and anything else (an opaque accessor/compute/relation/guarded
 * field, or a scalar whose column type cannot be resolved) is flagged
 * undocumented. The resolver never emits a concrete narrow type that was
 * neither declared nor inferred.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FieldTypeResolver
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper  $mapper
     */
    public function __construct(

        /** The provider used to introspect schema metadata. */
        private readonly SchemaIntrospectionProvider $introspector,

        /** The mapper that converts column types to schema types. */
        private readonly ColumnTypeMapper $mapper,

    ) {}

    /**
     * Resolve one field's emitted schema by precedence: declared, inferred,
     * then flagged.
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
     * Determine whether a field's value cannot be sourced directly from its
     * backing column, and so cannot be inferred.
     *
     * A field is opaque when it draws its value from an accessor, a computed
     * callback, or a relation, or when a guard or transformer intervenes
     * between the column and the emitted value.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return bool
     */
    private function isOpaque(CompiledFieldDefinition $field): bool
    {
        return $field->accessor     !== null
            || $field->compute      !== null
            || $field->relation     !== null
            || $field->guards       !== []
            || $field->transformers !== [];
    }

    /**
     * Infer a plain scalar field's schema from its backing column and model
     * cast, or flag it undocumented when no matching column exists.
     *
     * @param  string  $fieldKey
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    private function inferScalar(string $fieldKey, string $modelClass): OpenApiFieldSchema
    {
        $model  = new $modelClass;
        $column = $this->introspector->getColumnDefinitions($model)[$fieldKey] ?? null;

        if ($column === null) {
            return OpenApiFieldSchema::undocumented();
        }

        return $this->mapper->map($column, $this->resolveCast($model, $fieldKey));
    }

    /**
     * Resolve the model cast declared for the given attribute, or null when no
     * cast applies.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $fieldKey
     * @return string|null
     */
    private function resolveCast(Model $model, string $fieldKey): ?string
    {
        $cast = $model->getCasts()[$fieldKey] ?? null;

        return is_string($cast) ? $cast : null;
    }
}
