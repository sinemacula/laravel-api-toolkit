<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Pure domain rule that decides whether to narrow the base-table SELECT.
 *
 * Narrows only when every resolved field is provably column-mapped, returning
 * the union of all needed columns and the safety set. Falls back immediately
 * when any field's column reads are unknown.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ColumnNarrower
{
    /**
     * Decide whether to narrow the base-table select for the resolved field
     * set.
     *
     * @param  \SineMacula\ApiToolkit\Schema\FieldColumnMap  $map
     * @param  array<int, string>  $resolvedFields
     * @param  array<int, string>  $safetySetColumns
     * @return \SineMacula\ApiToolkit\Schema\NarrowingDecision
     */
    public function decide(FieldColumnMap $map, array $resolvedFields, array $safetySetColumns): NarrowingDecision
    {
        foreach ($resolvedFields as $field) {
            if (!$map->isMapped($field)) {
                return NarrowingDecision::fallback($field);
            }
        }

        $needed = array_merge(...array_map(fn ($field) => $map->columnsFor($field) ?? [], $resolvedFields));

        $columns = array_values(array_unique([...$needed, ...$safetySetColumns]));

        return NarrowingDecision::narrow($columns);
    }
}
