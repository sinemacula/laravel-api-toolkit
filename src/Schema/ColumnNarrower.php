<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Decides whether to narrow the base-table select to the columns it needs.
 *
 * Narrows only when every resolved field reads columns the table carries. A
 * declared column must exist; an inferred one must also be read by the model
 * without an accessor, a shadowing public property or a cast that may consult
 * other attributes. A name the schema does not declare renders nothing, so it
 * reads nothing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ColumnNarrower
{
    /** @var array<int, string> The Eloquent cast types that read only their own column */
    private const array PRIMITIVE_CAST_TYPES = [
        'array',
        'bool',
        'boolean',
        'collection',
        'custom_datetime',
        'date',
        'datetime',
        'decimal',
        'double',
        'encrypted',
        'float',
        'hashed',
        'immutable_date',
        'immutable_datetime',
        'immutable_custom_datetime',
        'int',
        'integer',
        'json',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    /** @var string The namespace of the cast classes Eloquent ships */
    private const string ELOQUENT_CASTS_NAMESPACE = 'Illuminate\Database\Eloquent\Casts\\';

    /**
     * Decide whether to narrow the base-table select for the resolved fields.
     *
     * @param  \SineMacula\ApiToolkit\Schema\FieldColumnMap  $map
     * @param  array<int, string>  $resolvedFields
     * @param  array<int, string>  $safetySetColumns
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<int, string>  $realColumns
     * @return \SineMacula\ApiToolkit\Schema\NarrowingDecision
     */
    public function decide(FieldColumnMap $map, array $resolvedFields, array $safetySetColumns, Model $model, array $realColumns): NarrowingDecision
    {
        $resolvedFields = array_filter($resolvedFields, $map->declares(...));

        foreach ($resolvedFields as $field) {
            if (!$this->isProven($map, $field, $model, $realColumns)) {
                return NarrowingDecision::fallback($field);
            }
        }

        $needed = array_merge(...array_map(fn ($field) => $map->columnsFor($field) ?? [], $resolvedFields));

        $columns = array_values(array_unique([...$needed, ...$safetySetColumns]));

        return NarrowingDecision::narrow($columns);
    }

    /**
     * Determine whether the field provably reads only real table columns.
     *
     * @param  \SineMacula\ApiToolkit\Schema\FieldColumnMap  $map
     * @param  string  $field
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<int, string>  $realColumns
     * @return bool
     */
    private function isProven(FieldColumnMap $map, string $field, Model $model, array $realColumns): bool
    {
        $columns = $map->columnsFor($field);

        if ($columns === null || array_diff($columns, $realColumns) !== []) {
            return false;
        }

        if (!$map->isInferred($field)) {
            return true;
        }

        [$column] = $columns;

        return !$model->hasAnyGetMutator($column)
            && !$this->declaresPublicProperty($model, $column)
            && $this->readsOnlyItsOwnColumn($model, $column);
    }

    /**
     * Determine whether a public instance property shadows the column's
     * attribute.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @return bool
     */
    private function declaresPublicProperty(Model $model, string $column): bool
    {
        if (!property_exists($model, $column)) {
            return false;
        }

        $property = new \ReflectionProperty($model, $column);

        return $property->isPublic() && !$property->isStatic();
    }

    /**
     * Determine whether the column's cast, if any, reads only that column.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @return bool
     */
    private function readsOnlyItsOwnColumn(Model $model, string $column): bool
    {
        $cast = $model->getCasts()[$column] ?? null;

        if (!is_string($cast)) {
            return true;
        }

        return $this->isTrustedCaster(Str::before($cast, ':'));
    }

    /**
     * Determine whether the caster is known to read only its own column.
     *
     * @param  string  $caster
     * @return bool
     */
    private function isTrustedCaster(string $caster): bool
    {
        return in_array($caster, self::PRIMITIVE_CAST_TYPES, true)
            || (enum_exists($caster) && !is_subclass_of($caster, Castable::class))
            || str_starts_with($caster, self::ELOQUENT_CASTS_NAMESPACE);
    }
}
