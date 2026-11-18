<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\Versioned;
use SineMacula\ApiToolkit\Support\PureEnumCast;
use SineMacula\ApiToolkit\Support\PureEnumName;

/**
 * Writes request attributes to a model through Eloquent.
 *
 * Every key is written with the model's own setAttribute(), so casts, mutators
 * and JSON paths apply. Only object casts, pure-enum casts and relations are
 * converted first.
 *
 * @phpstan-type Association \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>
 * @phpstan-type Synchronisation \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class AttributeSetter
{
    /** @var string A key written with the model's setAttribute() as given */
    private const string PLAIN = 'plain';

    /** @var string A key cast to an object, converted from an array first */
    private const string OBJECT = 'object';

    /** @var string A key cast to a pure enum, converted from its wire name first */
    private const string PURE_ENUM = 'pure_enum';

    /**
     * Create a new attribute setter instance.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @return void
     */
    public function __construct(

        /** The schema introspection provider for relation resolution. */
        private SchemaIntrospectionProvider $schemaIntrospector,
    ) {}

    /**
     * Persist the given attributes, saving the model and syncing its relations
     * in one transaction on its connection.
     *
     * Every key is classified before any is assigned, so a key naming a
     * relation that cannot be written leaves the model untouched. A cancelled
     * save syncs nothing and returns false. A versioned model whose relations
     * alone changed is saved again to advance its version.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>  $attributes
     * @return bool
     *
     * @throws \InvalidArgumentException
     * @throws \ValueError
     */
    public function persist(Model $model, array $attributes): bool
    {
        $casts  = $model->getCasts();
        $writes = [];

        foreach (array_keys($attributes) as $attribute) {
            $writes[$attribute] = $this->classify($model, $attribute, $casts);
        }

        $syncs = [];

        foreach ($writes as $attribute => $write) {

            if ($write instanceof BelongsToMany) {
                $syncs[$attribute] = $write;
                continue;
            }

            $this->write($model, $attribute, $attributes[$attribute], $write);
        }

        return $model->getConnection()->transaction(fn (): bool => $this->saveAndSync($model, $attributes, $syncs));
    }

    /**
     * Save the model, then sync its relations, advancing the version of a
     * versioned model whose relations alone changed.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>  $attributes
     * @param  array<string, Synchronisation>  $syncs
     * @return bool
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    private function saveAndSync(Model $model, array $attributes, array $syncs): bool
    {
        $version = $model instanceof Versioned ? $model->getRawOriginal($model->getVersionColumn()) : null;

        if (!$model->save()) {
            return false;
        }

        $synced = false;

        foreach ($syncs as $attribute => $relation) {
            $synced = $this->sync($model, $attribute, $attributes[$attribute], $relation) || $synced;
        }

        if ($synced && $model instanceof Versioned && $model->getRawOriginal($model->getVersionColumn()) === $version) {
            $this->recordRelationChange($model);
        }

        return true;
    }

    /**
     * Advance the version of a model whose relations alone changed.
     *
     * @param  \Illuminate\Database\Eloquent\Model&\SineMacula\ApiToolkit\Contracts\Versioned  $model
     * @return void
     *
     * @throws \LogicException
     */
    private function recordRelationChange(Model&Versioned $model): void
    {
        $model->markVersionChanged();

        if (!$model->save()) {
            throw new \LogicException(sprintf('%s refused the save that records its relation change, so the change is rolled back.', $model::class));
        }
    }

    /**
     * Classify how the given key is written, first match wins.
     *
     * A cast outranks a set mutator, and a set mutator outranks a relation of
     * the same name.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  array<string, mixed>  $casts
     * @return Association|string|Synchronisation
     *
     * @throws \InvalidArgumentException
     */
    private function classify(Model $model, string $attribute, array $casts): BelongsTo|BelongsToMany|string
    {
        $cast = $casts[$attribute] ?? null;

        if (is_string($cast)) {
            return $this->classifyCast($cast);
        }

        if ($model->hasSetMutator($attribute) || $model->hasAttributeSetMutator($attribute)) {
            return self::PLAIN;
        }

        return $this->classifyRelation($model, $attribute) ?? self::PLAIN;
    }

    /**
     * Classify a key by its model cast.
     *
     * @param  string  $cast
     * @return string
     */
    private function classifyCast(string $cast): string
    {
        if (PureEnumCast::enumOf($cast) !== null) {
            return self::PURE_ENUM;
        }

        return in_array($cast, ['object', 'encrypted:object'], true) ? self::OBJECT : self::PLAIN;
    }

    /**
     * Resolve the writable relation the key names, or null when it names none.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @return Association|Synchronisation|null
     *
     * @throws \InvalidArgumentException
     */
    private function classifyRelation(Model $model, string $attribute): BelongsTo|BelongsToMany|null
    {
        $relation = $this->schemaIntrospector->resolveRelation($attribute, $model);

        if ($relation === null || $relation instanceof BelongsTo || $relation instanceof BelongsToMany) {
            return $relation;
        }

        throw new \InvalidArgumentException(sprintf('Attribute "%s" on %s is a %s relation, which persist() cannot write', $attribute, $model::class, class_basename($relation)));
    }

    /**
     * Assign the given value to the model as its key was classified.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Association|string  $write
     * @return void
     *
     * @throws \InvalidArgumentException
     * @throws \ValueError
     */
    private function write(Model $model, string $attribute, mixed $value, BelongsTo|string $write): void
    {
        match (true) {
            $write instanceof BelongsTo => $this->associate($model, $attribute, $value, $write),
            $write === self::OBJECT     => $model->setAttribute($attribute, is_array($value) ? (object) $value : $value),
            $write === self::PURE_ENUM  => $model->setAttribute($attribute, $this->resolvePureEnumCase($model, $attribute, $value)),
            default                     => $model->setAttribute($attribute, $value),
        };
    }

    /**
     * Resolve a wire-form string written to a pure-enum attribute as its case.
     *
     * An unknown name throws ValueError, as an unknown backed-enum value does.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return mixed
     *
     * @throws \ValueError
     */
    private function resolvePureEnumCase(Model $model, string $attribute, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        /** @var class-string<\UnitEnum> $enum */
        $enum = $model->getCasts()[$attribute];

        return PureEnumName::tryFrom($enum, $value)
            ?? throw new \ValueError(sprintf('"%s" is not a valid wire name for enum %s', $value, $enum));
    }

    /**
     * Associate a BelongsTo relation from a model or its key, or a MorphTo
     * relation from a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Association  $relation
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private function associate(Model $model, string $attribute, mixed $value, BelongsTo $relation): void
    {
        if ($value === null || $value instanceof Model) {
            $relation->associate($value);

            return;
        }

        if ($relation instanceof MorphTo) {
            throw new \InvalidArgumentException(sprintf('Attribute "%s" on %s is a MorphTo relation and must be given a model', $attribute, $model::class));
        }

        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Attribute "%s" on %s must be given a model or its key', $attribute, $model::class));
        }

        $relation->associate($value);
    }

    /**
     * Sync a many-to-many relation from keys, a model or a model collection,
     * returning whether its membership changed.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Synchronisation  $relation
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    private function sync(Model $model, string $attribute, mixed $value, BelongsToMany $relation): bool
    {
        if ($value instanceof Model) {
            $value = collect([$value]);
        }

        if ($value instanceof Collection) {
            $value = $value->pluck($relation->getRelated()->getKeyName());
        }

        if (!is_array($value) && !$value instanceof Collection && !is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Attribute "%s" on %s must be given models or their keys', $attribute, $model::class));
        }

        return array_filter($relation->sync($value)) !== [];
    }
}
