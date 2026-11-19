<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Collection;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use SineMacula\ApiToolkit\Support\PureEnumName;

/**
 * Resolves field values and the aggregates payload from compiled definitions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class ValueResolver
{
    /** @var array<string, array<string, bool>> Cast-accessor reflection results, keyed by [class][field] */
    private static array $castAccessorCache = [];

    /** @var bool Whether the last resolved field cut its related collection */
    private bool $truncated = false;

    /** @var array<string, array<string, class-string<\Illuminate\Database\Eloquent\Model>|null>> Related model classes, keyed by [owner class][relation] */
    private static array $relatedModelCache = [];

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
     * Clear the cast-accessor reflection memo.
     *
     * The memo lives for the worker, so a lifecycle flush leaves it; this is
     * for test isolation.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$castAccessorCache = [];
        self::$relatedModelCache = [];
    }

    /**
     * Resolve a single field's value from the schema definition.
     *
     * A pure enum has no backing value, so it resolves to its lowercase name.
     *
     * @param  string  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    public function resolveFieldValue(string $field, CompiledFieldDefinition $definition, mixed $resource, ?Request $request): mixed
    {
        $this->truncated = false;

        if (!$this->guardEvaluator->passesCallerGuards($definition->callerGuards, $request)) {
            return new MissingValue;
        }

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

        return $value instanceof \UnitEnum && !$value instanceof \BackedEnum ? PureEnumName::of($value) : $value;
    }

    /**
     * Determine whether the last resolved field cut its related collection.
     *
     * @return bool
     */
    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Resolve the aggregates payload from compiled aggregate definitions.
     *
     * Each value is read raw, never through the owner's accessors or casts.
     *
     * @param  mixed  $resource
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @param  string  $resourceType
     * @param  \Illuminate\Http\Request|null  $request
     * @return array<string, mixed>
     */
    public function resolveAggregatesPayload(mixed $resource, CompiledSchema $schema, string $resourceType, ?Request $request): array
    {
        $owner = $this->unwrapResource($resource);

        if (!is_object($owner) || !method_exists($owner, 'getAttributes')) {
            return [];
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $owner->getAttributes();
        $requested  = (array) ApiQuery::getAggregates($resourceType);
        $result     = [];

        foreach ($schema->getAggregateDefinitions() as $presentKey => $definition) {

            $include = $requested !== []
                ? in_array($presentKey, $requested, true)
                : $definition->isDefault;

            if (!$include || !$this->hasRenderableAggregate($definition, $attributes) || !$this->guardEvaluator->passesCallerGuards($definition->callerGuards, $request)) {
                continue;
            }

            if (!$this->guardEvaluator->passesGuards($definition->guards, $resource, $request)) {
                continue;
            }

            $result[$presentKey] = $this->applyTransformers($definition->transformers, $resource, $this->readAggregate($owner, $definition, $attributes[$definition->attribute()]));
        }

        return $result;
    }

    /**
     * Access a simple property on the underlying resource model.
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
     * Determine whether the model exposes the given field by any means.
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
     * Determine whether the field is an Eloquent attribute or cast accessor.
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
     * Determine whether the field is an Eloquent cast accessor.
     *
     * @param  object  $model
     * @param  string  $field
     * @return bool
     */
    private function isCastAccessor(object $model, string $field): bool
    {
        return self::$castAccessorCache[$model::class][$field] ??= $this->computeIsCastAccessor($model, $field);
    }

    /**
     * Determine via reflection whether the field is an Eloquent cast accessor.
     *
     * @param  object  $model
     * @param  string  $field
     * @return bool
     */
    private function computeIsCastAccessor(object $model, string $field): bool
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
            is_string($compute) && is_object($resource) && method_exists($resource, $compute) => $resource->{$compute}($request), // @phpstan-ignore method.dynamicName
            is_callable($compute)                                                             => $compute($resource, $request),
            default                                                                           => new MissingValue,
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
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return mixed
     */
    private function resolveRelationValue(CompiledFieldDefinition $definition, mixed $resource, ?Request $request): mixed
    {
        $name  = $definition->relation;
        $owner = $this->unwrapResource($resource);

        if ($name === null || !is_object($owner) || !method_exists($owner, 'getRelation')) {
            return new MissingValue;
        }

        if (!method_exists($owner, 'relationLoaded') || !$owner->relationLoaded($name)) {
            return new MissingValue;
        }

        $related = $owner->getRelation($name);

        return $related === null ? null : $this->resolveRelatedValue($definition, $related, $resource, $request);
    }

    /**
     * Resolve a loaded relation through its accessor or child resource.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
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

        if ($childClass === null) {
            return $related;
        }

        $capped          = RelationLimit::cap($definition, $related);
        $this->truncated = $capped !== $related;

        return $this->wrapRelatedWithResource($capped, $childClass, $childFields);
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
     * Get explicit child fields from a relation definition, if provided.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @return array<int, string>|null
     */
    private function getRelationFields(CompiledFieldDefinition $definition): ?array
    {
        $fields = $definition->fields;

        if (!is_array($fields)) {
            return null;
        }

        return array_values(array_filter($fields, static fn ($f) => is_string($f) && $f !== '')); // @phpstan-ignore function.alreadyNarrowedType
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
     * Determine whether the owner carries a renderable value for the aggregate.
     *
     * A column aggregate over no related values is null and renders so; a null
     * count or existence check is left out.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $definition
     * @param  array<string, mixed>  $attributes
     * @return bool
     */
    private function hasRenderableAggregate(CompiledAggregateDefinition $definition, array $attributes): bool
    {
        $attribute = $definition->attribute();

        return array_key_exists($attribute, $attributes)
            && ($attributes[$attribute] !== null || $definition->function->requiresColumn());
    }

    /**
     * Read a raw aggregate value the way its function defines it.
     *
     * @param  object  $owner
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $definition
     * @param  mixed  $raw
     * @return mixed
     */
    private function readAggregate(object $owner, CompiledAggregateDefinition $definition, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($definition->function) {
            AggregateFunction::COUNT                       => (int) $raw, // @phpstan-ignore cast.int
            AggregateFunction::EXISTS                      => (bool) $raw,
            AggregateFunction::SUM, AggregateFunction::AVG => (float) $raw, // @phpstan-ignore cast.double
            AggregateFunction::MIN, AggregateFunction::MAX => $this->readRelatedValue($owner, $definition->relation, (string) $definition->column, $raw),
        };
    }

    /**
     * Read a raw minimum or maximum the way the related model reads its column.
     *
     * The related model's cast, date format and dates apply in its own context;
     * an owner without the relation returns the raw value.
     *
     * @param  object  $owner
     * @param  string  $relation
     * @param  string  $column
     * @param  mixed  $raw
     * @return mixed
     */
    private function readRelatedValue(object $owner, string $relation, string $column, mixed $raw): mixed
    {
        if (!array_key_exists($relation, self::$relatedModelCache[$owner::class] ?? [])) {
            $instance = method_exists($owner, $relation) ? $owner->{$relation}() : null; // @phpstan-ignore method.dynamicName

            self::$relatedModelCache[$owner::class][$relation] = $instance instanceof Relation ? $instance->getRelated()::class : null;
        }

        $relatedClass = self::$relatedModelCache[$owner::class][$relation];

        if ($relatedClass === null) {
            return $raw;
        }

        return (new $relatedClass)->setRawAttributes([$column => $raw])->getAttribute($column);
    }
}
