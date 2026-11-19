<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;

/**
 * Selects the pivot columns a many-to-many relation renders on each child, and
 * locates the accessor they load under.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class PivotColumns
{
    /** @var array<string, array<string, string|null>> Pivot accessors of many-to-many relations, keyed by [owner class][relation] */
    private static array $accessorCache = [];

    /**
     * Clear the pivot-accessor memo.
     *
     * The memo lives for the worker, so a lifecycle flush leaves it; this is
     * for test isolation.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$accessorCache = [];
    }

    /**
     * Select the pivot columns on the eager-loaded relation, refusing one that
     * is not many-to-many.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @param  list<string>  $columns
     * @param  string  $owner
     * @return void
     *
     * @throws \LogicException
     */
    public static function select(EloquentRelation $relation, array $columns, string $owner): void
    {
        if (!$relation instanceof BelongsToMany) {
            throw new \LogicException(sprintf('The %s relation declares pivot columns but is not a many-to-many relation', $owner));
        }

        $relation->withPivot($columns);
    }

    /**
     * Resolve the pivot accessor of the owner's many-to-many relation, or null
     * when the relation is not one.
     *
     * @param  object  $owner
     * @param  string  $relation
     * @return string|null
     */
    public static function accessor(object $owner, string $relation): ?string
    {
        if (!array_key_exists($relation, self::$accessorCache[$owner::class] ?? [])) {
            $instance = $owner instanceof Model && $owner->isRelation($relation) ? $owner->{$relation}() : null; // @phpstan-ignore method.dynamicName

            self::$accessorCache[$owner::class][$relation] = $instance instanceof BelongsToMany ? $instance->getPivotAccessor() : null;
        }

        return self::$accessorCache[$owner::class][$relation];
    }
}
