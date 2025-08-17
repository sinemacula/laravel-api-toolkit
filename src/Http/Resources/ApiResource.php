<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use LogicException;
use SensitiveParameter;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Facades\ApiQuery;

/**
 * The base API resource.
 *
 * Handles dynamic field filtering and eager loading based on API query
 * parameters and the resource schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class ApiResource extends BaseResource implements ApiResourceInterface
{
    /** @var array Default fields to include if no specific fields are requested */
    protected static array $default = [];

    /** @var array Fixed fields to include in the response */
    protected array $fixed = [];

    /** @var array<class-string, array<string, array>> Compiled schema cache keyed by resource class */
    private static array $schemaCache = [];

    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);

        if (is_object($resource) && method_exists($resource, 'loadMissing')) {

            $fields = $this->shouldRespondWithAll()
                ? array_keys(static::getCompiledSchema())
                : $this->resolveFields();

            $relations = static::relationsFor($fields);

            if (!empty($relations)) {
                $resource->loadMissing($relations);
            }
        }
    }

    /**
     * Resolve the resource to an array.
     *
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing
     *
     * @param  mixed  $request
     * @return array<string, mixed>
     */
    public function resolve(#[SensitiveParameter] $request = null): array
    {
        // phpcs:enable
        $data   = ['_type' => static::getResourceType()];
        $fields = $this->getFields();

        foreach ($fields as $field) {

            $value = $this->resolveFieldValue($field, $request);

            if (!($value instanceof MissingValue)) {
                $data[$field] = $value;
            }
        }

        $priority = ['_type' => 0, 'id' => 1];

        uksort(
            $data,
            static fn (string $a, string $b): int => ($priority[$a] ?? 2) <=> ($priority[$b] ?? 2) ?: strcmp($a, $b)
        );

        return $data;
    }

    /**
     * Get relation paths to eager load based on the provided fields.
     *
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    public static function relationsFor(array $fields): array
    {
        $schema = static::getCompiledSchema();
        $paths  = [];

        foreach ($fields as $field) {

            $definition = $schema[$field] ?? null;

            if ($definition === null) {
                continue;
            }

            $relations = isset($definition['relation']) ? (array) $definition['relation'] : [];
            $extras    = is_array($definition['extras'] ?? null) ? $definition['extras'] : [];

            $paths = array_merge($paths, $relations, $extras);
        }

        $paths = array_filter($paths, static fn ($path) => is_string($path) && $path !== '');

        return array_values(array_unique($paths));
    }

    /**
     * Get the resource type.
     *
     * @return string
     */
    public static function getResourceType(): string
    {
        if (!defined(static::class . '::RESOURCE_TYPE')) {
            throw new LogicException('The RESOURCE_TYPE constant must be defined on the resource');
        }

        return strtolower(static::RESOURCE_TYPE);
    }

    /**
     * Get the default fields for this resource.
     *
     * @return array<int, string>
     */
    public static function getDefaultFields(): array
    {
        return static::$default;
    }

    /**
     * Return the resource schema.
     *
     * @return array<string, array>
     */
    abstract public static function schema(): array;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resolve($request);
    }

    /**
     * Get the compiled schema for this resource class.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function getCompiledSchema(): array
    {
        $class = static::class;

        if (isset(self::$schemaCache[$class])) {
            return self::$schemaCache[$class];
        }

        return self::$schemaCache[$class] = $class::schema();
    }

    /**
     * Resolve the requested field names from the API query or return defaults,
     * including fixed fields.
     *
     * @return array<int, string>
     */
    protected function getFields(): array
    {
        $this->fields ??= $this->shouldRespondWithAll()
            ? array_keys(static::getCompiledSchema())
            : $this->resolveFields();

        $resolved = array_diff($this->fields, $this->excludedFields ?? []);
        $merged   = array_merge($resolved, $this->getFixedFields());

        return array_values(array_unique($merged));
    }

    /**
     * Create a new resource collection instance.
     *
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection
     */
    protected static function newCollection(#[SensitiveParameter] $resource): ApiResourceCollection
    {
        // phpcs:enable
        return new ApiResourceCollection($resource, static::class);
    }

    /**
     * Resolve a single field value using the schema (guards first).
     *
     * @param  string  $field
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    protected function resolveFieldValue(string $field, ?Request $request): mixed
    {
        $definition = static::getCompiledSchema()[$field] ?? null;

        if ($definition !== null && !empty($definition['guards'])) {
            foreach ($definition['guards'] as $guard) {
                if (is_callable($guard) && $guard($this, $request) === false) {
                    return new MissingValue;
                }
            }
        }

        $value = match (true) {
            $definition === null                      => $this->resolveSimpleProperty($field),
            array_key_exists('compute', $definition)  => $this->resolveComputedValue($definition['compute'], $request),
            array_key_exists('relation', $definition) => $this->resolveRelationValue($definition, $request),
            array_key_exists('accessor', $definition) => $this->resolveAccessorValue($definition['accessor'], $request),
            default                                   => new MissingValue
        };

        if (!($value instanceof MissingValue) && !empty($definition['transformers'])) {
            $value = $this->applyTransformers($definition['transformers'], $value);
        }

        return $value;
    }

    /**
     * Access a simple property on the underlying resource.
     *
     * @param  string  $field
     * @return mixed
     */
    protected function resolveSimpleProperty(string $field): mixed
    {
        return is_object($this->resource)
        && (property_exists($this->resource, $field) || isset($this->resource->{$field}))
            ? $this->resource->{$field}
            : new MissingValue;
    }

    /**
     * Resolve a computed field value.
     *
     * @param  mixed  $compute
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    protected function resolveComputedValue(mixed $compute, ?Request $request): mixed
    {
        return match (true) {
            is_string($compute) && method_exists($this, $compute) => $this->{$compute}($request),
            is_callable($compute)                                 => $compute($this, $request),
            default                                               => new MissingValue
        };
    }

    /**
     * Resolve an accessor field value from a string path or callable.
     *
     * @param  mixed  $accessor
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    protected function resolveAccessorValue(mixed $accessor, ?Request $request): mixed
    {
        return match (true) {
            is_string($accessor)   => data_get($this->resource, $accessor),
            is_callable($accessor) => $accessor($this, $request),
            default                => new MissingValue
        };
    }

    /**
     * Resolve a relation field without triggering lazy loading.
     *
     * @param  array<string, mixed>  $definition
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    protected function resolveRelationValue(array $definition, ?Request $request): mixed
    {
        $name = $this->getPrimaryRelationName($definition);

        if ($name === null || !$this->isRelationLoaded($name)) {
            return new MissingValue;
        }

        $related = $this->resource->getRelation($name);

        if (is_string($definition['accessor'] ?? null)) {
            return data_get($related, $definition['accessor']);
        }

        $child = $this->getRelationResourceClass($definition);

        return $child === null ? $related : $this->wrapRelatedWithResource($related, $child);
    }

    /**
     * Return the primary relation name from a relation definition.
     *
     * @param  array<string, mixed>  $definition
     * @return string|null
     */
    private function getPrimaryRelationName(array $definition): ?string
    {
        $paths = (array) ($definition['relation'] ?? null);
        $name  = $paths[0] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Check whether a relation is already loaded on the model.
     *
     * @param  string  $name
     * @return bool
     */
    private function isRelationLoaded(string $name): bool
    {
        return is_object($this->resource)
            && method_exists($this->resource, 'relationLoaded')
            && $this->resource->relationLoaded($name);
    }

    /**
     * Return the child resource class from a relation definition.
     *
     * @param  array<string, mixed>  $definition
     * @return class-string|null
     */
    private function getRelationResourceClass(array $definition): ?string
    {
        $class = $definition['resource'] ?? null;

        return is_string($class) && $class !== '' ? $class : null;
    }

    /**
     * Wrap a related value with the given child resource class.
     *
     * @param  mixed  $related
     * @param  class-string  $resource
     * @return mixed
     */
    private function wrapRelatedWithResource(mixed $related, string $resource): mixed
    {
        return $related instanceof Collection
            ? $resource::collection($related)
            : new $resource($related);
    }

    /**
     * Apply an array of transformers to a value.
     *
     * @param  array<int, callable>  $transformers
     * @param  mixed  $value
     * @return mixed
     */
    private function applyTransformers(array $transformers, mixed $value): mixed
    {
        foreach ($transformers as $transformer) {
            if (is_callable($transformer)) {
                $value = $transformer($this, $value);
            }
        }

        return $value;
    }

    /**
     * Resolves and returns the fields based on the API query or defaults.
     *
     * @return array<int, string>
     */
    private function resolveFields(): array
    {
        return ApiQuery::getFields(static::getResourceType()) ?? static::getDefaultFields();
    }

    /**
     * Determines whether all fields should be included in the response.
     *
     * @return bool
     */
    private function shouldRespondWithAll(): bool
    {
        return $this->all || in_array(':all', ApiQuery::getFields(self::getResourceType()) ?? [], true);
    }

    /**
     * Gets the fields that should always be included in the response.
     *
     * @return array<int, string>
     */
    private function getFixedFields(): array
    {
        return array_merge(Config::get('api-toolkit.resources.fixed_fields', []), $this->fixed);
    }
}
