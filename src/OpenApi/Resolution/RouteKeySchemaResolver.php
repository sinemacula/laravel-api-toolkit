<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Resolves the schema of the route key an implicitly bound model is looked up
 * by.
 *
 * A model whose binding lookup is overridden yields no schema, since the column
 * no longer states what the segment accepts, and so does an abstract model the
 * container binds to a concrete one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RouteKeySchemaResolver
{
    /** The schema types a route key segment can carry */
    private const array SCALAR_TYPES = ['string', 'integer', 'number'];

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver  $fields
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator  $patterns
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $enums
     */
    public function __construct(

        /** The metadata catalogue mapping models to their resources. */
        private MetadataCatalogue $catalogue,

        /** The resolver of a column's schema. */
        private FieldTypeResolver $fields,

        /** The translator providing the UUID and ULID schemas. */
        private RoutePatternTranslator $patterns,

        /** The registry collecting enum classes referenced by a $ref. */
        private EnumSchemaRegistry $enums,
    ) {}

    /**
     * Resolve the route key's schema, or null when it cannot be stated.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  string|null  $field
     * @param  bool  $withTrashed
     * @return array<string, mixed>|null
     */
    public function resolve(string $modelClass, ?string $field, bool $withTrashed): ?array
    {
        if (!(new \ReflectionClass($modelClass))->isInstantiable() || $this->hasCustomLookup($modelClass, $withTrashed)) {
            return null;
        }

        $model = new $modelClass;
        $key   = $field ?? $model->getRouteKeyName();

        return $this->uniqueIdSchema($model, $key) ?? $this->columnSchema($model, $key);
    }

    /**
     * Determine whether the binding method Laravel calls is overridden.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  bool  $withTrashed
     * @return bool
     */
    private function hasCustomLookup(string $modelClass, bool $withTrashed): bool
    {
        $method = $withTrashed && $modelClass::isSoftDeletable()
            ? 'resolveSoftDeletableRouteBinding'
            : 'resolveRouteBinding';

        return (new \ReflectionMethod($modelClass, $method))->getDeclaringClass()->getName() !== Model::class;
    }

    /**
     * Resolve the schema of a key the model's unique id trait validates.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @return array<string, mixed>|null
     */
    private function uniqueIdSchema(Model $model, string $key): ?array
    {
        if (!in_array($key, $model->uniqueIds(), true)) {
            return null;
        }

        $traits = class_uses_recursive($model);

        return match (true) {
            isset($traits[HasUuids::class]) => $this->patterns->uuidSchema(),
            isset($traits[HasUlids::class]) => $this->patterns->ulidSchema(),
            default                         => null,
        };
    }

    /**
     * Resolve the schema of a key from its column: a scalar without null, else
     * a reference to the enum the key is cast to.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @return array<string, mixed>|null
     */
    private function columnSchema(Model $model, string $key): ?array
    {
        $schema = $this->fields->resolveColumn($key, $model::class, $this->resourceField($model::class, $key));

        if ($schema->ref === null && in_array($schema->type, self::SCALAR_TYPES, true)) {
            return $schema->withoutNull()->toArray();
        }

        $cast = $model->getCasts()[$key] ?? null;

        return is_string($cast) && enum_exists($cast) ? ['$ref' => $this->enums->reference($cast)] : null;
    }

    /**
     * Read the field named after the key on the model's mapped resource.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  string  $key
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition|null
     */
    private function resourceField(string $modelClass, string $key): ?CompiledFieldDefinition
    {
        $resourceClass = $this->catalogue->getResourceMap()[$modelClass] ?? null;

        return $resourceClass === null ? null : SchemaCompiler::compile($resourceClass)->getField($key);
    }
}
