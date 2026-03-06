<?php

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Collection;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;

/**
 * Resolves individual field values and counts payload from compiled schema
 * definitions.
 *
 * Dispatches to the appropriate resolution strategy (simple property, accessor,
 * computed, or relation) based on the compiled field definition, delegating
 * guard evaluation to the injected GuardEvaluator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValueResolver
{
    /**
     * Create a new value resolver instance.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator  $guardEvaluator
     */
    public function __construct(

        /** The guard evaluator for field visibility checks */
        private readonly GuardEvaluator $guardEvaluator,

    ) {}

    /**
     * Resolve a single field's value from the schema definition.
     *
     * Uses a match expression to dispatch to the appropriate resolution
     * strategy: compute, relation, accessor, or simple property. Guards are
     * evaluated first, and transformers are applied to the resolved value.
     *
     * @param  string  $field
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition  $definition
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    public function resolveFieldValue(string $field, CompiledFieldDefinition $definition, mixed $resource, ?Request $request): mixed
    {
        if (!$this->guardEvaluator->passesGuards($definition->guards, $resource, $request)) {
            return new MissingValue;
        }

        $value = match (true) {
            $definition->compute  !== null => $this->resolveComputedValue($definition->compute, $resource, $request),
            $definition->relation !== null => $this->resolveRelationValue($definition, $resource, $request),
            $definition->accessor !== null => $this->resolveAccessorValue($definition->accessor, $resource, $request),
            default                        => $this->resolveSimpleProperty($field, $resource),
        };

        if (!($value instanceof MissingValue) && $definition->transformers !== []) {
            $value = $this->applyTransformers($definition->transformers, $resource, $value);
        }

        return $value;
    }

    /**
     * Resolve the counts payload from compiled count definitions.
     *
     * Iterates over the compiled schema's count definitions, including only
     * those that match the requested aliases (or defaults) and pass guard
     * evaluation.
     *
     * @param  mixed  $resource
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema  $schema
     * @param  string  $resourceType
     * @param  \Illuminate\Http\Request|null  $request
     * @return array<string, int>
     */
    public function resolveCountsPayload(mixed $resource, CompiledSchema $schema, string $resourceType, ?Request $request): array
    {
        $owner = $this->unwrapResource($resource);

        if (!is_object($owner)) {
            return [];
        }

        $requested = ApiQuery::getCounts($resourceType) ?? [];
        $result    = [];

        foreach ($schema->getCountDefinitions() as $presentKey => $definition) {

            if (!$this->shouldIncludeCount($presentKey, $requested, $definition)) {
                continue;
            }

            if (!$this->guardEvaluator->passesGuards($definition->guards, $resource, $request)) {
                continue;
            }

            $attribute = $definition->relation . '_count';
            $value     = $this->getAttributeIfLoaded($owner, $attribute);

            if ($value !== null) {
                $result[$presentKey] = (int) $value; // @phpstan-ignore cast.int
            }
        }

        return $result;
    }

    /**
     * Access a simple property on the underlying resource model.
     *
     * Checks for direct properties, Eloquent attributes, appended attributes,
     * Eloquent cast accessors, and magic __isset before falling back to a
     * MissingValue.
     *
     * @param  string  $field
     * @param  mixed  $resource
     * @return mixed
     */
    private function resolveSimpleProperty(string $field, mixed $resource): mixed
    {
        $model = $this->unwrapResource($resource);

        if (!is_object($model)) {
            return new MissingValue;
        }

        return $this->hasAccessibleProperty($model, $field)
            ? $model->{$field} // @phpstan-ignore property.dynamicName
            : new MissingValue;
    }

    /**
     * Determine whether the model exposes the given field through any
     * accessible path (property, attribute, appended, cast accessor, or magic
     * __isset).
     *
     * @param  object  $model
     * @param  string  $field
     * @return bool
     */
    private function hasAccessibleProperty(object $model, string $field): bool
    {
        if (property_exists($model, $field)) {
            return true;
        }

        if (method_exists($model, 'getAttributes') && $this->isEloquentAccessible($model, $field)) {
            return true;
        }

        return method_exists($model, '__isset') && $model->__isset($field);
    }

    /**
     * Check whether the field is accessible as an Eloquent attribute, appended
     * attribute, or cast accessor.
     *
     * @param  object  $model
     * @param  string  $field
     * @return bool
     */
    private function isEloquentAccessible(object $model, string $field): bool
    {
        $attributes = $model->getAttributes(); // @phpstan-ignore method.notFound

        if (array_key_exists($field, $attributes)) {
            return true;
        }

        if (property_exists($model, 'appends') && in_array($field, $model->appends ?? [], true)) {
            return true;
        }

        return $this->isCastAccessor($model, $field);
    }

    /**
     * Determine whether the field is an Eloquent cast accessor (returns
     * Attribute).
     *
     * @param  object  $model
     * @param  string  $field
     * @return bool
     */
    private function isCastAccessor(object $model, string $field): bool
    {
        if (!method_exists($model, $field)) {
            return false;
        }

        $method     = new \ReflectionMethod($model, $field);
        $returnType = $method->getReturnType();

        return $returnType instanceof \ReflectionNamedType
            && $returnType->getName() === Attribute::class;
    }

    /**
     * Resolve a computed field value from a method name or callable.
     *
     * @param  mixed  $compute
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    private function resolveComputedValue(mixed $compute, mixed $resource, ?Request $request): mixed
    {
        return match (true) {
            is_string($compute) && method_exists($resource, $compute) => $resource->{$compute}($request), // @phpstan-ignore method.dynamicName
            is_callable($compute)                                     => $compute($resource, $request),
            default                                                   => new MissingValue,
        };
    }

    /**
     * Resolve an accessor field value from a string path or callable.
     *
     * @param  mixed  $accessor
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    private function resolveAccessorValue(mixed $accessor, mixed $resource, ?Request $request): mixed
    {
        $model = $this->unwrapResource($resource);

        return match (true) {
            is_string($accessor)   => data_get($model, $accessor),
            is_callable($accessor) => $accessor($resource, $request),
            default                => new MissingValue,
        };
    }

    /**
     * Resolve a relation field without triggering lazy loading.
     *
     * Returns a MissingValue when the relation is not loaded, null when the
     * related model is null, or wraps the related model with the appropriate
     * child resource class.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition  $definition
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    private function resolveRelationValue(CompiledFieldDefinition $definition, mixed $resource, ?Request $request): mixed
    {
        $name  = $definition->relation;
        $owner = $this->unwrapResource($resource);

        if ($name === null || !is_object($owner) || !method_exists($owner, 'getRelation') || !$this->isRelationLoaded($name, $resource)) {
            return new MissingValue;
        }

        $related = $owner->getRelation($name);

        return $related === null ? null : $this->resolveRelatedValue($definition, $related, $resource, $request);
    }

    /**
     * Resolve the final value for a loaded relation, dispatching to accessor
     * resolution or child resource wrapping as appropriate.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition  $definition
     * @param  mixed  $related
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    private function resolveRelatedValue(CompiledFieldDefinition $definition, mixed $related, mixed $resource, ?Request $request): mixed
    {
        if ($definition->accessor !== null) {
            return match (true) {
                is_string($definition->accessor)   => data_get($related, $definition->accessor),
                is_callable($definition->accessor) => ($definition->accessor)($resource, $request),
                default                            => null,
            };
        }

        $childClass  = $definition->resource;
        $childFields = $this->getRelationFields($definition);

        return $childClass === null ? $related : $this->wrapRelatedWithResource($related, $childClass, $childFields);
    }

    /**
     * Unwrap nested JsonResource layers to the underlying model or collection.
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
     * Check whether a relation is already loaded on the model that owns it.
     *
     * @param  string  $name
     * @param  mixed  $resource
     * @return bool
     */
    private function isRelationLoaded(string $name, mixed $resource): bool
    {
        $owner = $this->unwrapResource($resource);

        return is_object($owner)
            && method_exists($owner, 'relationLoaded')
            && $owner->relationLoaded($name);
    }

    /**
     * Get explicit child fields from a relation definition, if provided.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition  $definition
     * @return array<int, string>|null
     */
    private function getRelationFields(CompiledFieldDefinition $definition): ?array
    {
        $fields = $definition->fields;

        if (!is_array($fields)) {
            return null;
        }

        $fields = array_values(array_filter($fields, static fn ($f) => is_string($f) && $f !== '')); // @phpstan-ignore function.alreadyNarrowedType

        return $fields === [] ? [] : $fields;
    }

    /**
     * Wrap a related value with the given child resource class.
     *
     * @param  mixed  $related
     * @param  string  $resource
     * @param  array<int, string>|null  $fields
     * @return mixed
     */
    private function wrapRelatedWithResource(mixed $related, string $resource, ?array $fields = null): mixed
    {
        /** @var object $wrapped */
        $wrapped = $related instanceof Collection
            ? $resource::collection($related)
            : new $resource($related, false, $fields);

        if ($fields !== null && method_exists($wrapped, 'withFields')) {
            $wrapped->withFields($fields);
        }

        return $wrapped;
    }

    /**
     * Apply an array of transformers to a resolved value.
     *
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @param  mixed  $resource
     * @param  mixed  $value
     * @return mixed
     */
    private function applyTransformers(array $transformers, mixed $resource, mixed $value): mixed
    {
        foreach ($transformers as $transformer) {
            $value = $transformer($resource, $value);
        }

        return $value;
    }

    /**
     * Decide if a count should be included based on request or default flag.
     *
     * @param  string  $presentKey
     * @param  array<int, string>|null  $requested
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition  $definition
     * @return bool
     */
    private function shouldIncludeCount(string $presentKey, ?array $requested, CompiledCountDefinition $definition): bool
    {
        if (is_array($requested) && $requested !== []) {
            return in_array($presentKey, $requested, true);
        }

        return $definition->isDefault;
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
        if (method_exists($owner, 'getAttributes')) {

            $attrs = $owner->getAttributes();

            if (array_key_exists($attr, $attrs)) {
                return $owner->{$attr}; // @phpstan-ignore property.dynamicName
            }
        }

        if (method_exists($owner, '__isset') && $owner->__isset($attr)) {
            return $owner->{$attr}; // @phpstan-ignore property.dynamicName
        }

        return null;
    }
}
