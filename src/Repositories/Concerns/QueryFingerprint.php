<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Derives a stable, collision-resistant fingerprint for a prepared query
 * builder so that distinct queries map to distinct cache keys.
 *
 * The fingerprint combines the connection name, the compiled SQL, and the
 * normalised bindings, ensuring a filtered or by-id read never collides with
 * a full-table read.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryFingerprint
{
    /**
     * Build a fingerprint for the given prepared query builder.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @return string
     */
    public static function for(Builder $query): string
    {
        $base = $query->getQuery();

        $connection = $base->getConnection()->getDatabaseName();
        $bindings   = self::normaliseBindings($base->getBindings());

        return hash('xxh128', $connection . '|' . $base->toSql() . '|' . $bindings);
    }

    /**
     * Normalise the query bindings into a stable string representation.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return string
     */
    private static function normaliseBindings(array $bindings): string
    {
        $normalised = array_map(fn (mixed $value): mixed => self::normaliseValue($value), $bindings);

        $encoded = json_encode($normalised);

        return $encoded === false ? serialize($normalised) : $encoded;
    }

    /**
     * Normalise a single binding value into a comparable scalar form.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}
