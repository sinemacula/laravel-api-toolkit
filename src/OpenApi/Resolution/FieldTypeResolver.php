<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
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
     * Resolve a column's schema, reusing the declaration of a field that emits
     * the column unchanged.
     *
     * A guard only hides the value, so a guarded field's declaration is reused.
     *
     * @param  string  $column
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition|null  $field
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    public function resolveColumn(string $column, string $modelClass, ?CompiledFieldDefinition $field = null): OpenApiFieldSchema
    {
        if ($field?->openApi !== null && !$this->hasAlternateSource($field) && $field->transformers === []) {
            return $field->openApi;
        }

        return $this->inferScalar($column, $modelClass);
    }

    /**
     * Determine whether a field's value cannot be inferred from its column.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return bool
     */
    private function isOpaque(CompiledFieldDefinition $field): bool
    {
        return $this->hasAlternateSource($field) || $this->hasInterceptors($field);
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
     * Determine whether a guard or transformer intervenes between the column
     * and the emitted value.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return bool
     */
    private function hasInterceptors(CompiledFieldDefinition $field): bool
    {
        return $field->guards !== [] || $field->transformers !== [];
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
     * Resolve the model cast declared for the given attribute.
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
