<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
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
    /** The path prefix under which component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

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
            return $this->enumReference($cast, $column !== null && $column->nullable);
        }

        if ($column === null && $cast === null) {
            return OpenApiFieldSchema::undocumented();
        }

        return $this->mapper->map($column, $cast);
    }

    /**
     * Build a reference to the enum's component schema, registering the enum.
     *
     * @param  class-string  $cast
     * @param  bool  $nullable
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    private function enumReference(string $cast, bool $nullable): OpenApiFieldSchema
    {
        $this->enums->register($cast);

        return OpenApiFieldSchema::reference(self::SCHEMA_REF_PREFIX . SchemaComponentName::fromEnum($cast), $nullable);
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
