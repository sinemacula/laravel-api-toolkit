<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SineMacula\ApiToolkit\Schema\RelationLimit;

/**
 * Applies a field's constraint and per-parent bound to an eager-loaded
 * relation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RelationWindow
{
    /** The global scope that orders a bounded relation by its related key */
    private const string KEY_ORDER_SCOPE = 'api-toolkit:relation-key-order';

    /**
     * Run the field's constraint on the relation, refusing one that bounds it.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $constraint
     * @param  string  $owner
     * @return void
     *
     * @throws \LogicException
     */
    public static function constrain(EloquentRelation $relation, \Closure $constraint, string $owner): void
    {
        $query  = $relation->getQuery()->getQuery();
        $before = self::bounds($query);

        $constraint($relation);

        if (self::bounds($query) === $before) {
            return;
        }

        throw new \LogicException(sprintf('The constraint on %s may not limit or offset the relation; declare limit() on the relation instead', $owner));
    }

    /**
     * Bound a to-many relation per parent, keeping one extra row as a
     * truncation probe.
     *
     * The key order is a global scope, so it runs after the model's own scopes.
     * A bound the relation method already declares tighter is kept. A query
     * with unions is left to the render cap, since a window would drop them.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @param  int  $limit
     * @return void
     */
    public static function bound(EloquentRelation $relation, int $limit): void
    {
        if ($limit === 0 || !RelationLimit::appliesTo($relation) || $relation->getQuery()->getQuery()->unions !== null) {
            return;
        }

        $key = $relation->getRelated()->getQualifiedKeyName();

        $relation->getQuery()->withGlobalScope(self::KEY_ORDER_SCOPE, static function (Builder $query) use ($key): void {
            $query->orderBy($key);
        });

        $existing = $relation->getQuery()->getQuery()->groupLimit['value'] ?? null;

        $relation->limit(is_int($existing) ? min($existing, $limit + 1) : $limit + 1);
    }

    /**
     * Return the limit and offset clauses of the given query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return list<mixed>
     */
    private static function bounds(QueryBuilder $query): array
    {
        return [$query->limit, $query->offset, $query->groupLimit, $query->unionLimit, $query->unionOffset];
    }
}
