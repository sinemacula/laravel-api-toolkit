<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RowPerimeter;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that a model loads no relation by default whose rows a resource
 * restricts.
 *
 * A default load runs before any row rule can be applied, so its rows would
 * reach the response unrestricted.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateRowPerimeterDefaults implements SchemaValidationRule
{
    /**
     * Validate the default loads of the model the resource serves.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        $resourceMap = ResourceSettings::resourceMap();

        if ($modelClass === null || !is_subclass_of($modelClass, Model::class) || array_filter($resourceMap, RowPerimeter::restricts(...)) === []) {
            return [];
        }

        $model  = new $modelClass;
        $errors = [];

        foreach (self::defaults($model) as [$property, $path, $relations]) {

            $defect = self::reach($model, $relations, $resourceMap);

            if ($defect === null) {
                continue;
            }

            $errors[] = new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $path,
                defect: sprintf('Model "%s" loads "%s" by default through $%s, which %s; load it explicitly instead', $modelClass, $path, $property, $defect),
            );
        }

        return $errors;
    }

    /**
     * List each default load and count of the model, with the property naming
     * it and the relation path it loads.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return list<array{string, string, string}>
     *
     * @SuppressWarnings("php:S3011")
     */
    private static function defaults(Model $model): array
    {
        $defaults = [];

        foreach (['with' => ':', 'withCount' => ' as '] as $property => $suffix) {
            foreach ((array) (new \ReflectionProperty(Model::class, $property))->getValue($model) as $key => $value) {

                $path = is_int($key) ? $value : $key;

                if (!is_string($path)) {
                    continue;
                }

                $defaults[] = [$property, $path, Str::before($path, $suffix)];
            }
        }

        return $defaults;
    }

    /**
     * Describe how the relation path reaches a restricted or unresolvable
     * relation, or return null when every relation along it is unrestricted.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $path
     * @param  array<string, string>  $resourceMap
     * @return string|null
     */
    private static function reach(Model $model, string $path, array $resourceMap): ?string
    {
        foreach (explode('.', $path) as $segment) {

            $relation = RowPerimeter::relationOf($model, $segment);

            if ($relation === null || $relation instanceof MorphTo) {
                return sprintf('cannot be resolved to the resource governing "%s"', $segment);
            }

            $model    = $relation->getRelated();
            $resource = $resourceMap[$model::class] ?? null;

            if (RowPerimeter::restricts($resource)) {
                return sprintf('reaches "%s", whose rows %s restricts', $segment, $resource);
            }
        }

        return null;
    }
}
