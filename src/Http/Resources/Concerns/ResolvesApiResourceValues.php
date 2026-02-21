<?php

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Collection;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;

/**
 * Resolves resource field values and related payload fragments.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @internal
 */
trait ResolvesApiResourceValues
{
    /**
     * Resolve a single field value using schema definition and guards.
     *
     * @param  string  $field
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    protected function resolveFieldValue(string $field, ?Request $request): mixed
    {
        $definition = static::getCompiledSchema()[$field] ?? null;

        if (($definition['metric'] ?? null) !== null || !$this->passesGuards($definition, $request)) {
            return new MissingValue;
        }

        $value = match (true) {
            array_key_exists('compute', $definition ?? [])  => $this->resolveComputedValue($definition['compute'], $request),
            array_key_exists('relation', $definition ?? []) => $this->resolveRelationValue($definition, $request),
            array_key_exists('accessor', $definition ?? []) => $this->resolveAccessorValue($definition['accessor'], $request),
            default                                         => $this->resolveSimpleProperty($field),
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
        $value = new MissingValue;

        if (!is_object($this->resource)) {
            return $value;
        }

        $resolved = $this->readPublicProperty($this->resource, $field);

        if (!($resolved instanceof MissingValue)) {
            $value = $resolved;
        }

        if ($value instanceof MissingValue) {
            $resolved = $this->resolveAttributeProperty($this->resource, $field);

            if (!($resolved instanceof MissingValue)) {
                $value = $resolved;
            }
        }

        if ($value instanceof MissingValue) {
            $value = $this->resolveDynamicProperty($this->resource, $field);
        }

        return $value;
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
            is_string($compute) && method_exists($this, $compute) => call_user_func([$this, $compute], $request),
            is_callable($compute)                                 => $compute($this, $request),
            default                                               => new MissingValue,
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
            default                => new MissingValue,
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
        $value = new MissingValue;
        $name  = $this->getPrimaryRelationName($definition);

        if ($name === null || !$this->isRelationLoaded($name)) {
            return $value;
        }

        $owner = $this->unwrapResource($this->resource);

        if (!is_object($owner) || !method_exists($owner, 'getRelation')) {
            return $value;
        }

        $related = $owner->getRelation($name);

        if ($related === null) {
            $value = null;
        } else {
            $accessed = $this->resolveRelationAccessorValue($definition, $related, $request);

            if (!($accessed instanceof MissingValue)) {
                $value = $accessed;
            }

            if ($value instanceof MissingValue) {
                $child        = $this->getRelationResourceClass($definition);
                $child_fields = $this->getRelationFields($definition);
                $value        = $child === null ? $related : $this->wrapRelatedWithResource($related, $child, $child_fields);
            }
        }

        return $value;
    }

    /**
     * Do all guards on this definition pass?
     *
     * @param  array<string, mixed>|null  $definition
     * @param  \Illuminate\Http\Request|null  $request
     * @return bool
     */
    protected function passesGuards(?array $definition = null, ?Request $request = null): bool
    {
        $guards = $definition['guards'] ?? [];

        if (!is_array($guards)) {
            return true;
        }

        foreach ($guards as $guard) {
            if (is_callable($guard) && $guard($this, $request) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Eager-load missing relations/counts for constructor opt-in mode.
     *
     * @param  object  $resource
     * @return void
     */
    private function loadMissingRelations(object $resource): void
    {
        if (method_exists($resource, 'loadMissing')) {
            $fields = $this->shouldRespondWithAll()
                ? static::getAllFields()
                : static::resolveFields();

            $with = static::eagerLoadMapFor($fields);

            if ($with !== []) {
                $resource->loadMissing($with);
            }
        }

        if (!method_exists($resource, 'loadCount') || !$this->shouldIncludeCountsField()) {
            return;
        }

        $requested_counts = ApiQuery::getCounts(static::getResourceType()) ?? [];
        $with_counts      = static::eagerLoadCountsFor($requested_counts);

        if ($with_counts !== []) {
            $resource->loadCount($with_counts);
        }
    }

    /**
     * Safely read an attribute without triggering lazy loads.
     *
     * @param  object  $owner
     * @param  string  $attr
     * @return mixed
     */
    private function getAttributeIfLoaded(object $owner, string $attr): mixed
    {
        if (method_exists($owner, 'getAttributes') && method_exists($owner, 'getAttribute')) {
            $attributes = $owner->getAttributes();

            if (array_key_exists($attr, $attributes)) {
                return $owner->getAttribute($attr);
            }
        }

        if (method_exists($owner, '__isset') && $owner->__isset($attr)) {
            return method_exists($owner, 'getAttribute')
                ? $owner->getAttribute($attr)
                : data_get($owner, $attr);
        }

        return null;
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

        return array_values(
            array_filter($fields, static fn ($field): bool => is_string($field) && $field !== ''),
        );
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
     * Check whether a relation is already loaded on the owning model.
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

        return is_string($class) && $class !== '' && is_subclass_of($class, ApiResource::class)
            ? $class
            : null;
    }

    /**
     * Wrap a related value with the given child resource class.
     *
     * @param  mixed  $related
     * @param  class-string  $resource
     * @param  array<int, string>|null  $fields
     * @return mixed
     */
    private function wrapRelatedWithResource(mixed $related, string $resource, ?array $fields = null): mixed
    {
        $wrapped = $related instanceof Collection
            ? $resource::collection($related)
            : new $resource($related, false, $fields);

        if ($fields !== null && $wrapped instanceof ApiResource) {
            $wrapped->withFields($fields);
        }

        if ($fields !== null && $wrapped instanceof ApiResourceCollection) {
            $wrapped->withFields($fields);
        }

        return $wrapped;
    }

    /**
     * Apply an array of transformers to a value.
     *
     * @param  array<int, callable(self, mixed): mixed>  $transformers
     * @param  mixed  $value
     * @return mixed
     */
    private function applyTransformers(array $transformers, mixed $value): mixed
    {
        foreach ($transformers as $transformer) {
            $value = $transformer($this, $value);
        }

        return $value;
    }

    /**
     * Resolve value from attribute-backed access.
     *
     * @param  object  $resource
     * @param  string  $field
     * @return mixed
     */
    private function resolveAttributeProperty(object $resource, string $field): mixed
    {
        $value = new MissingValue;

        if (!method_exists($resource, 'getAttributes') || !method_exists($resource, 'getAttribute')) {
            return $value;
        }

        $attributes = $resource->getAttributes();
        $appends    = method_exists($resource, 'getAppends') ? $resource->getAppends() : [];

        if (array_key_exists($field, $attributes) || in_array($field, $appends, true)) {
            $value = $resource->getAttribute($field);
        }

        if ($value instanceof MissingValue && method_exists($resource, $field)) {
            $method      = new \ReflectionMethod($resource, $field);
            $return_type = $method->getReturnType();

            if ($return_type instanceof \ReflectionNamedType && $return_type->getName() === Attribute::class) {
                $value = $resource->getAttribute($field);
            }
        }

        return $value;
    }

    /**
     * Resolve value through dynamic access fallback.
     *
     * @param  object  $resource
     * @param  string  $field
     * @return mixed
     */
    private function resolveDynamicProperty(object $resource, string $field): mixed
    {
        if (method_exists($resource, '__isset') && $resource->__isset($field)) {
            return method_exists($resource, 'getAttribute')
                ? $resource->getAttribute($field)
                : data_get($resource, $field);
        }

        return new MissingValue;
    }

    /**
     * Resolve an accessor override on an already loaded relation.
     *
     * @param  array<string, mixed>  $definition
     * @param  mixed  $related
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    private function resolveRelationAccessorValue(array $definition, mixed $related, ?Request $request): mixed
    {
        if (!array_key_exists('accessor', $definition)) {
            return new MissingValue;
        }

        $accessor = $definition['accessor'];

        return match (true) {
            is_string($accessor)   => data_get($related, $accessor),
            is_callable($accessor) => $accessor($this, $request),
            default                => null,
        };
    }

    /**
     * Read a public object property safely.
     *
     * @param  object  $resource
     * @param  string  $field
     * @return mixed
     */
    private function readPublicProperty(object $resource, string $field): mixed
    {
        if (!property_exists($resource, $field)) {
            return new MissingValue;
        }

        try {
            return data_get($resource, $field);
        } catch (\Throwable $exception) {
            return new MissingValue;
        }
    }
}
