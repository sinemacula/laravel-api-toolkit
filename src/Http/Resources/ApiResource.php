<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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

    /** @var array<class-string, array<string, array>> Compiled schema cache keyed by resource class */
    private static array $schemaCache = [];

    /** @var array Fixed fields to include in the response */
    protected array $fixed = [];

    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     * @param  mixed  $included
     * @param  array|null  $excluded
     */
    public function __construct(mixed $resource, mixed $included = null, ?array $excluded = null)
    {
        parent::__construct($resource);

        if (is_array($included)) {
            $this->withFields($included);
        }

        if ($excluded !== null) {
            $this->withoutFields($excluded);
        }

        if (is_object($resource) && method_exists($resource, 'loadMissing')) {

            $fields = $this->shouldRespondWithAll()
                ? array_keys(static::getCompiledSchema())
                : $this->resolveFields();

            $with = static::eagerLoadMapFor($fields);

            if (!empty($with)) {
                $resource->loadMissing($with);
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

        return $this->orderResolvedFields($data);
    }

    /**
     * Build a with()-ready eager-load map for the provided fields, including constrained relations.
     *
     * - Numeric entries are plain eager-loads: ['user', 'user.roles']
     * - Associative entries are scoped with a closure: ['bindings' => fn ($q) => ...]
     *
     * @param  array<int, string>  $fields
     * @return array<int|string, mixed>
     */
    public static function eagerLoadMapFor(array $fields): array
    {
        $plain   = [];
        $scoped  = [];
        $visited = [];

        static::walkRelationsWith(static::class, $fields, '', $plain, $scoped, $visited);

        if ($plain === [] && $scoped === []) {
            return [];
        }

        return array_merge($plain, $scoped);
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
     * Order resolved fields into a predictable output structure.
     *
     * Rules:
     *  - "_type" always first
     *  - "id" always second
     *  - any timestamps (*_at) always last
     *  - everything else alphabetized in between
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function orderResolvedFields(array $data): array
    {
        $weight = static function (string $key): array {

            if ($key === '_type') {
                return [0, ''];
            }

            if ($key === 'id') {
                return [1, ''];
            }

            $is_timestamp = str_ends_with($key, '_at');

            return [$is_timestamp ? 3 : 2, $key];
        };

        uksort($data, static function (string $a, string $b) use ($weight): int {

            [$wa, $ka] = $weight($a);
            [$wb, $kb] = $weight($b);

            return $wa <=> $wb ?: strcmp($ka, $kb);
        });

        return $data;
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
            $definition === null || $definition === [] => $this->resolveSimpleProperty($field),
            array_key_exists('compute', $definition)   => $this->resolveComputedValue($definition['compute'], $request),
            array_key_exists('relation', $definition)  => $this->resolveRelationValue($definition, $request),
            array_key_exists('accessor', $definition)  => $this->resolveAccessorValue($definition['accessor'], $request),
            default                                    => new MissingValue
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

        $owner   = $this->unwrapResource($this->resource);
        $related = $owner->getRelation($name);

        if (is_string($definition['accessor'] ?? null)) {
            return data_get($related, $definition['accessor']);
        }

        $child        = $this->getRelationResourceClass($definition);
        $child_fields = $this->getRelationFields($definition);

        return $child === null ? $related : $this->wrapRelatedWithResource($related, $child, $child_fields);
    }

    /**
     * Get explicit child fields from a relation definition, if provided.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>|null
     */
    private function getRelationFields(array $definition): ?array
    {
        $fields = $definition['fields'] ?? null;

        if (!is_array($fields)) {
            return null;
        }

        $fields = array_values(array_filter($fields, static fn ($field) => is_string($field) && $field !== ''));

        return $fields === [] ? [] : $fields;
    }

    /**
     * Orchestrate relation traversal for a resource class (constrained map mode).
     *
     * Builds both:
     *  - $plain   (numeric list of paths without constraints)
     *  - $scoped  (assoc: path => closure(EloquentBuilder): void)
     *
     * @param  class-string  $resource
     * @param  array<int, string>  $fields
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<string, bool>  $visited
     * @return void
     */
    private static function walkRelationsWith(string $resource, array $fields, string $prefix, array &$plain, array &$scoped, array &$visited): void
    {
        $schema = $resource::getCompiledSchema();

        foreach ($fields as $field) {

            $definition = static::findDefinition($schema, $field);

            if ($definition === null) {
                continue;
            }

            $relations   = static::extractRelations($definition);
            $extra_paths = static::extractExtraPaths($definition);
            $constraint  = static::extractConstraint($definition);

            foreach ($relations as $relation) {

                $full_path = static::makePrefixedPath($prefix, $relation);

                if (static::wasVisited($visited, $resource, $full_path)) {
                    continue;
                }

                static::markVisited($visited, $resource, $full_path);

                if ($constraint instanceof Closure) {
                    $scoped[$full_path] = static function ($query) use ($constraint): void {

                        if ($query instanceof MorphTo) {
                            $constraint($query);
                            return;
                        }

                        $builder = $query instanceof EloquentRelation ? $query->getQuery() : $query;

                        if ($builder instanceof Builder) {
                            $constraint($builder);
                        }
                    };
                } else {
                    $plain[] = $full_path;
                }

                foreach ($extra_paths as $extra) {
                    $plain[] = static::makePrefixedPath($prefix, $extra);
                }

                if (!static::shouldRecurseIntoChild($definition)) {
                    continue;
                }

                $child_resource = $definition['resource'];
                $child_fields   = static::resolveChildFields($definition, $child_resource);

                if ($child_fields !== []) {
                    static::walkRelationsWith($child_resource, $child_fields, $full_path, $plain, $scoped, $visited);
                }
            }
        }
    }

    /**
     * Find a field definition in a schema.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  string  $field
     * @return array<string, mixed>|null
     */
    private static function findDefinition(array $schema, string $field): ?array
    {
        $definition = $schema[$field] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * Extract declared relation names from a definition.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private static function extractRelations(array $definition): array
    {
        $relations = isset($definition['relation']) ? (array) $definition['relation'] : [];

        return array_values(
            array_filter($relations, static fn ($relation) => is_string($relation) && $relation !== '')
        );
    }

    /**
     * Extract extra eager-load paths from a definition.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private static function extractExtraPaths(array $definition): array
    {
        $extras = is_array($definition['extras'] ?? null) ? $definition['extras'] : [];

        return array_values(
            array_filter($extras, static fn ($path) => is_string($path) && $path !== '')
        );
    }

    /**
     * Extract a scoped eager-load constraint from a relation definition, if
     * present.
     *
     * @param  array<string, mixed>  $definition
     * @return Closure|null
     */
    private static function extractConstraint(array $definition): ?Closure
    {
        $constraint = $definition['constraint'] ?? null;

        return $constraint instanceof Closure ? $constraint : null;
    }

    /**
     * Build a dot-prefixed path.
     *
     * @param  string  $prefix
     * @param  string  $suffix
     * @return string
     */
    private static function makePrefixedPath(string $prefix, string $suffix): string
    {
        return $prefix === '' ? $suffix : $prefix . '.' . $suffix;
    }

    /**
     * Check if a (resource_class, path) pair has been visited.
     *
     * @param  array<string, bool>  $visited
     * @param  string  $resource
     * @param  string  $path
     * @return bool
     */
    private static function wasVisited(array $visited, string $resource, string $path): bool
    {
        return isset($visited[$resource . '|' . $path]);
    }

    /**
     * Mark a (resource_class, path) pair as visited.
     *
     * @param  array<string, bool>  $visited
     * @param  string  $resource
     * @param  string  $path
     * @return void
     */
    private static function markVisited(array &$visited, string $resource, string $path): void
    {
        $visited[$resource . '|' . $path] = true;
    }

    /**
     * Add a path to the accumulation list.
     *
     * @param  array<int, string>  $paths
     * @param  string  $path
     * @return void
     */
    private static function addPath(array &$paths, string $path): void
    {
        $paths[] = $path;
    }

    /**
     * Add any extra eager-load paths, respecting the current prefix.
     *
     * @param  array<int, string>  $paths
     * @param  string  $prefix
     * @param  array<int, string>  $extras
     * @return void
     */
    private static function addExtras(array &$paths, string $prefix, array $extras): void
    {
        foreach ($extras as $extra) {
            $paths[] = static::makePrefixedPath($prefix, $extra);
        }
    }

    /**
     * Decide whether to recurse into a child resource.
     *
     * @param  array<string, mixed>  $definition
     * @return bool
     */
    private static function shouldRecurseIntoChild(array $definition): bool
    {
        return isset($definition['resource'])
            && is_string($definition['resource'])
            && $definition['resource'] !== ''
            && is_subclass_of($definition['resource'], self::class);
    }

    /**
     * Resolve the child fields to traverse (relation override or child defaults / schema).
     *
     * @param  array<string, mixed>  $definition
     * @param  class-string  $resource
     * @return array<int, string>
     */
    private static function resolveChildFields(array $definition, string $resource): array
    {
        if (!empty($definition['fields']) && is_array($definition['fields'])) {
            return array_values(
                array_filter(
                    $definition['fields'],
                    static fn ($f) => is_string($f) && $f !== ''
                )
            );
        }

        if (is_subclass_of($resource, self::class) && method_exists($resource, 'getResourceType')) {

            $childType = $resource::getResourceType();
            $requested = ApiQuery::getFields($childType);

            if (is_array($requested) && !empty($requested)) {
                return array_values(
                    array_filter(
                        $requested,
                        static fn ($f) => is_string($f) && $f !== ''
                    )
                );
            }
        }

        $defaults = $resource::getDefaultFields();

        if (!empty($defaults)) {
            return $defaults;
        }

        return array_keys($resource::getCompiledSchema());
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
     * Unwrap nested JsonResource layers to the underlying model/collection.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function unwrapResource(mixed $value): mixed
    {
        while ($value instanceof JsonResource) {
            $value = $value->resource;
        }

        return $value;
    }

    /**
     * Check whether a relation is already loaded on the *model* that owns it.
     *
     * @param  string  $name
     * @return bool
     */
    private function isRelationLoaded(string $name): bool
    {
        $owner = $this->unwrapResource($this->resource);

        return is_object($owner)
            && method_exists($owner, 'relationLoaded')
            && $owner->relationLoaded($name);
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
     * @param  ?array  $fields
     * @return mixed
     */
    private function wrapRelatedWithResource(mixed $related, string $resource, ?array $fields = null): mixed
    {
        if ($related instanceof Collection) {

            $wrapped = $resource::collection($related);

            if ($fields !== null && method_exists($wrapped, 'withFields')) {
                $wrapped->withFields($fields);
            }

            return $wrapped;
        }

        return new $resource($related, $fields);
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
