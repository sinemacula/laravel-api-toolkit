<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Database\Eloquent\Castable;
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
 * no longer states what the segment accepts. So do an abstract model, a model
 * bound in the container, and a key cast to an enum Eloquent does not store by
 * its backing value.
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
     * @param  \Illuminate\Contracts\Container\Container  $container
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

        /** The container that resolves the bound model's instance. */
        private Container $container,
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
        if (!$this->isResolvedAsDeclared($modelClass) || $this->hasCustomLookup($modelClass, $withTrashed)) {
            return null;
        }

        $model = new $modelClass;
        $key   = $field ?? $model->getRouteKeyName();

        return $this->uniqueIdSchema($model, $key) ?? $this->columnSchema($model, $key);
    }

    /**
     * Determine whether binding instantiates the declared class itself.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return bool
     */
    private function isResolvedAsDeclared(string $modelClass): bool
    {
        return (new \ReflectionClass($modelClass))->isInstantiable() && !$this->container->bound($modelClass);
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
     * Resolve the schema of a key the stock UUID or ULID validator checks.
     *
     * The validator is told apart by its file, since a trait method reports the
     * using class as its declaring class.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @return array<string, mixed>|null
     */
    private function uniqueIdSchema(Model $model, string $key): ?array
    {
        if (!in_array($key, $model->uniqueIds(), true) || !method_exists($model, 'isValidUniqueId')) {
            return null;
        }

        return match ((new \ReflectionMethod($model, 'isValidUniqueId'))->getFileName()) {
            (new \ReflectionClass(HasUuids::class))->getFileName() => $this->patterns->uuidSchema(),
            (new \ReflectionClass(HasUlids::class))->getFileName() => $this->patterns->ulidSchema(),
            default                                                => null,
        };
    }

    /**
     * Resolve the schema of a key from its column: a scalar without null, else
     * a reference to the backed enum the key is cast to.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @return array<string, mixed>|null
     */
    private function columnSchema(Model $model, string $key): ?array
    {
        $cast = $model->getCasts()[$key] ?? null;
        $enum = is_string($cast) && enum_exists($cast) ? $cast : null;

        if ($enum !== null && !self::isStoredByValue($enum)) {
            return null;
        }

        $schema = $this->fields->resolveColumn($key, $model::class, $this->resourceField($model::class, $key));

        if ($schema->ref === null && in_array($schema->type, self::SCALAR_TYPES, true)) {
            return $schema->withoutNull()->toArray();
        }

        return $enum === null ? null : ['$ref' => $this->enums->reference($enum)];
    }

    /**
     * Determine whether Eloquent stores the enum by its backing value, the
     * value the binding query compares the segment with.
     *
     * @param  class-string<\UnitEnum>  $enum
     * @return bool
     */
    private static function isStoredByValue(string $enum): bool
    {
        return is_subclass_of($enum, \BackedEnum::class) && !is_subclass_of($enum, Castable::class);
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
