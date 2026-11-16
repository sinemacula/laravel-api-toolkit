<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Support\Facades\Config;

/**
 * Resolves how many related records a resource relation renders per parent.
 *
 * The planner, the renderer and the exporter all read the bound from here, so
 * the loaded window, the rendered cap and the documented maximum agree.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RelationLimit
{
    /** The response key listing the relations cut to their limit */
    public const string TRUNCATED_KEY = '_truncated';

    /**
     * Return the effective limit of the field's relation, or 0 when unbounded.
     *
     * Only a relation rendered through a child resource is bounded.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return int
     */
    public static function for(CompiledFieldDefinition $field): int
    {
        if ($field->relation === null || $field->resource === null || $field->accessor !== null) {
            return 0;
        }

        return $field->limit ?? self::default();
    }

    /**
     * Determine whether the relation bounds an eager load per parent in SQL.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @return bool
     */
    public static function appliesTo(EloquentRelation $relation): bool
    {
        return $relation instanceof HasMany
            || $relation instanceof MorphMany
            || $relation instanceof BelongsToMany
            || $relation instanceof HasManyThrough;
    }

    /**
     * Return the configured limit of a relation that declares none.
     *
     * @return int
     */
    private static function default(): int
    {
        $limit = Config::get('api-toolkit.resources.relation_limit');

        return is_numeric($limit) ? max(0, (int) $limit) : 0;
    }
}
