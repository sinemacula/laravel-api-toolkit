<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Applies each resource's row rule to the queries that read its rows.
 *
 * A related row is governed by the resource its parent declares over the
 * relation, else by the resource mapped to its model. A resource that does not
 * restrict its rows leaves every query it governs untouched.
 *
 * @phpstan-import-type AggregateLoad from \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
 *
 * @phpstan-type LoadConstraint \Closure(mixed, mixed=): mixed
 * @phpstan-type RowConstraint \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RowPerimeter
{
    /**
     * Create a new row perimeter.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array<string, string>  $resourceMap
     */
    public function __construct(

        /** Passed to each row rule to identify the caller */
        private Request $request,

        /** Model classes mapped to the resource serving them */
        private array $resourceMap,
    ) {}

    /**
     * Determine whether the resource restricts the rows it exposes.
     *
     * @param  string|null  $resource
     * @return bool
     */
    public static function restricts(?string $resource): bool
    {
        return $resource !== null
            && is_subclass_of($resource, ApiResource::class)
            && (new \ReflectionMethod($resource, 'restrictRows'))->getDeclaringClass()->getName() !== ApiResource::class;
    }

    /**
     * Resolve the named relation by calling its method, as Eloquent does, or
     * return null when the method returns no relation.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $name
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    public static function relationOf(Model $model, string $name): ?EloquentRelation
    {
        try {
            $relation = $model->{$name}(); // @phpstan-ignore method.dynamicName
        } catch (\BadMethodCallException) {
            return null;
        }

        return $relation instanceof EloquentRelation ? $relation : null;
    }

    /**
     * Return the child resource the owner declares over the relation, if any.
     *
     * @param  string|null  $owner
     * @param  string  $relation
     * @return string|null
     */
    public static function declaredResource(?string $owner, string $relation): ?string
    {
        if (!is_subclass_of($owner, ApiResource::class)) {
            return null;
        }

        foreach (SchemaCompiler::compile($owner)->getFields() as $field) {
            if ($field->relation === $relation && $field->resource !== null && is_subclass_of($field->resource, ApiResource::class)) {
                return $field->resource;
            }
        }

        return null;
    }

    /**
     * Add the resource's row rule to the query as one nested where group.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  string|null  $resource
     * @return void
     *
     * @throws \LogicException
     */
    public function constrain(Builder $query, ?string $resource): void
    {
        if (!self::restricts($resource)) {
            return;
        }

        /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource> $resource */
        $rule = $query->getModel()->newModelQuery();

        $resource::restrictRows($rule, $this->request);

        if (!self::addsOnlyWheres($rule)) {
            throw new \LogicException(sprintf('The row rule of %s may only add where clauses to the query', $resource));
        }

        $query->getQuery()->addNestedWhereQuery($rule->getQuery());
    }

    /**
     * Decorate the eager-load map so every path applies the row rule of the
     * resource governing it, and every aggregate its resource loads.
     *
     * Every prefix of a path loads too, so each is considered. A path whose
     * relation cannot be resolved ahead of the load, or that is polymorphic,
     * resolves its resource from the model it loads.
     *
     * @param  array<int|string, array<mixed>|(\Closure(mixed): mixed)|string>  $with
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  array<string, string>  $pathResources
     * @return array<int|string, array<mixed>|(\Closure(mixed): mixed)|string>
     */
    public function decorate(array $with, Model $root, array $pathResources = []): array
    {
        if ($with === [] || !$this->restrictsAny($pathResources)) {
            return $with;
        }

        return $this->decorateLoads($with, $root, $pathResources);
    }

    /**
     * Build an eager-load constraint running the existing one, then the row
     * rule of the given resource, else of the resource mapped to the model the
     * relation loads.
     *
     * The existing constraint is handed an aggregate decorator, which the
     * planner's closures apply to the aggregates the resource loads.
     *
     * @param  LoadConstraint|null  $existing
     * @param  string|null  $resource
     * @return \Closure(mixed): void
     */
    public function wrap(?\Closure $existing, ?string $resource = null): \Closure
    {
        $decorateAggregates = $resource === null ? null : function (array $entries) use ($resource): array {
            /** @var list<AggregateLoad> $entries */
            return $this->wrapAggregates($entries, $resource);
        };

        return function (mixed $query) use ($existing, $resource, $decorateAggregates): void {

            if ($existing !== null) {
                $existing($query, $decorateAggregates);
            }

            $this->constrainLoad($query, $resource);
        };
    }

    /**
     * Decorate the aggregate entries an owner resource loads so each counts
     * only the rows the request may see.
     *
     * @param  list<AggregateLoad>  $entries
     * @param  string  $owner
     * @return list<AggregateLoad>
     */
    public function wrapAggregates(array $entries, string $owner): array
    {
        foreach ($entries as $index => $entry) {

            $constraints = is_array($entry['relation']) ? $entry['relation'] : [$entry['relation'] => null];
            $wrapped     = [];

            foreach ($constraints as $relation => $constraint) {

                $rule = $this->wrapAggregate($constraint, $owner, Str::before($relation, ' as '));

                if ($rule === null) {
                    continue 2;
                }

                $wrapped[$relation] = $rule;
            }

            $entries[$index]['relation'] = $wrapped;
        }

        return $entries;
    }

    /**
     * Build an aggregate constraint running the existing one, then the row rule
     * of the resource the owner declares over the relation, else of the
     * resource mapped to the related model.
     *
     * @param  RowConstraint|null  $existing
     * @param  string  $owner
     * @param  string  $relation
     * @return RowConstraint|null
     */
    public function wrapAggregate(?\Closure $existing, string $owner, string $relation): ?\Closure
    {
        $declared = self::declaredResource($owner, $relation);

        if ($declared !== null ? !self::restricts($declared) : !$this->restrictsAny()) {
            return $existing;
        }

        return $this->ruleAfter($existing, $declared);
    }

    /**
     * Wrap each restricting path of the eager-load map, but the given ones, or
     * return the map as it is when none restricts.
     *
     * The map is parsed for its paths only: parsing hides a closure's second
     * argument, through which the planner's closures take the aggregate
     * decorator, so each closure the map names is wrapped as given.
     *
     * @param  array<int|string, array<mixed>|(\Closure(mixed): mixed)|string>  $with
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  array<string, string>  $pathResources
     * @param  array<int, string>  $skip
     * @return array<int|string, array<mixed>|(\Closure(mixed): mixed)|string>
     */
    private function decorateLoads(array $with, Model $root, array $pathResources, array $skip = []): array
    {
        $decorated = [];
        $wrapped   = false;

        foreach ($root->newModelQuery()->with($with)->getEagerLoads() as $path => $parsed) {

            $constraint = ($with[$path] ?? null) instanceof \Closure ? $with[$path] : $parsed;
            $wrapper    = in_array($path, $skip, true) ? null : $this->wrapPath($constraint, $root, $path, $pathResources[$path] ?? null);

            $decorated[$path] = $wrapper ?? $constraint;
            $wrapped          = $wrapped || $wrapper !== null;
        }

        return $wrapped ? $decorated : $with;
    }

    /**
     * Wrap the path's constraint with the row rule governing it, or return null
     * when neither its resource nor an aggregate its resource loads restricts.
     *
     * @param  LoadConstraint  $constraint
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  string  $path
     * @param  string|null  $declared
     * @return (\Closure(mixed): void)|null
     */
    private function wrapPath(\Closure $constraint, Model $root, string $path, ?string $declared): ?\Closure
    {
        $model = $root;

        foreach (explode('.', $path) as $segment) {

            $relation = $model === null ? null : self::relationOf($model, $segment);

            if ($relation instanceof MorphTo) {
                return $this->wrap($constraint);
            }

            $model = $relation?->getRelated();
        }

        if ($declared === null && $model === null) {
            return $this->wrap($constraint);
        }

        $resource = $declared ?? $this->mappedResource($model);

        return self::restricts($resource) || ($declared !== null && $this->restrictsAggregatesOf($declared)) ? $this->wrap($constraint, $resource) : null;
    }

    /**
     * Apply the row rule to the relation an eager load is building.
     *
     * @param  mixed  $query
     * @param  string|null  $resource
     * @return void
     */
    private function constrainLoad(mixed $query, ?string $resource): void
    {
        if ($query instanceof MorphTo) {
            $this->constrainMorph($query);

            return;
        }

        $builder = $query instanceof EloquentRelation ? $query->getQuery() : $query;

        if (!$builder instanceof Builder) {
            return;
        }

        $this->constrain($builder, $resource ?? $this->mappedResource($builder->getModel()));
    }

    /**
     * Apply the row rule of each restricting type a MorphTo may load, and to
     * the relations and counts it loads per type.
     *
     * A MorphTo replaces a type's constraint, relations and counts wholesale,
     * so each is read back and rewritten complete.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>  $relation
     * @return void
     */
    private function constrainMorph(MorphTo $relation): void
    {
        $constraints = self::readMorph($relation, 'morphableConstraints');
        $callbacks   = [];

        foreach ($this->resourceMap as $model => $resource) {

            if (!self::restricts($resource)) {
                continue;
            }

            $existing          = $constraints[$model] ?? null;
            $callbacks[$model] = $this->ruleAfter(is_callable($existing) ? \Closure::fromCallable($existing) : null, $resource);
        }

        $generic = $relation->getQuery()->getEagerLoads();
        $loads   = [];
        $counts  = [];

        // A generic load the type does not replace is already decorated
        foreach (self::readMorph($relation, 'morphableEagerLoads') as $type => $with) {

            /** @var array<int|string, array<mixed>|(\Closure(mixed): mixed)|string> $merged */
            $merged = [...$generic, ...(array) $with];

            $loads[$type] = $this->decorateLoads($merged, new $type, [], array_keys(array_diff_key($generic, (array) $with)));
        }

        foreach (self::readMorph($relation, 'morphableEagerLoadCounts') as $type => $withCount) {
            $counts[$type] = $this->wrapCounts((array) $withCount);
        }

        $relation->constrain($callbacks)->morphWith($loads)->morphWithCount($counts);
    }

    /**
     * Wrap each count of a withCount() list with the row rule of the resource
     * mapped to the counted model.
     *
     * @param  array<int|string, mixed>  $withCount
     * @return array<string, RowConstraint>
     */
    private function wrapCounts(array $withCount): array
    {
        $counts = [];

        foreach ($withCount as $key => $value) {

            $name = is_int($key) ? $value : $key;

            if (!is_string($name)) {
                continue;
            }

            $counts[$name] = $this->ruleAfter(is_string($key) && is_callable($value) ? \Closure::fromCallable($value) : null, null);
        }

        return $counts;
    }

    /**
     * Build a constraint running the existing one, then the row rule of the
     * given resource, else of the resource mapped to the queried model.
     *
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): mixed)|null  $existing
     * @param  string|null  $resource
     * @return RowConstraint
     */
    private function ruleAfter(?\Closure $existing, ?string $resource): \Closure
    {
        return function (Builder $query) use ($existing, $resource): void {

            if ($existing !== null) {
                $existing($query);
            }

            $this->constrain($query, $resource ?? $this->mappedResource($query->getModel()));
        };
    }

    /**
     * Determine whether any mapped or given resource, or an aggregate a given
     * resource loads, restricts its rows.
     *
     * @param  array<string, string>  $resources
     * @return bool
     */
    private function restrictsAny(array $resources = []): bool
    {
        foreach ([...$this->resourceMap, ...$resources] as $resource) {
            if (self::restricts($resource)) {
                return true;
            }
        }

        foreach ($resources as $resource) {
            if ($this->restrictsAggregatesOf($resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether any aggregate the resource loads counts rows a resource
     * restricts.
     *
     * @param  string  $resource
     * @return bool
     */
    private function restrictsAggregatesOf(string $resource): bool
    {
        foreach (SchemaCompiler::compile($resource)->getAggregateDefinitions() as $aggregate) {
            if ($this->wrapAggregate(null, $resource, $aggregate->relation) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the resource mapped to the model, if any.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     * @return string|null
     */
    private function mappedResource(?Model $model): ?string
    {
        return $model === null ? null : $this->resourceMap[$model::class] ?? null;
    }

    /**
     * Determine whether the rule query carries nothing but where clauses.
     *
     * Any other clause would be dropped by the nested group, and a scope the
     * rule registers or removes would leak into the outer query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $rule
     * @return bool
     */
    private static function addsOnlyWheres(Builder $rule): bool
    {
        $base    = $rule->getQuery();
        $clauses = [
            $base->joins, $base->columns, $base->distinct ?: null, $base->indexHint, $base->orders, $base->groups, $base->havings,
            $base->limit, $base->groupLimit, $base->offset, $base->unions, $base->lock, $base->beforeQueryCallbacks,
            $rule->getEagerLoads(), $rule->removedScopes(),
        ];

        return array_filter($clauses, static fn (mixed $clause): bool => $clause !== null && $clause !== []) === []
            && $base->from === $rule->getModel()->getTable()
            && $rule->applyScopes() === $rule;
    }

    /**
     * Read a MorphTo's per-type map, which it exposes no reader for.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>  $relation
     * @param  string  $property
     * @return array<class-string<\Illuminate\Database\Eloquent\Model>, mixed>
     *
     * @SuppressWarnings("php:S3011")
     */
    private static function readMorph(MorphTo $relation, string $property): array
    {
        $value = (new \ReflectionProperty(MorphTo::class, $property))->getValue($relation);

        return is_array($value) ? $value : [];
    }
}
