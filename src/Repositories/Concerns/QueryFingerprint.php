<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Derives a stable, collision-resistant fingerprint for a prepared query
 * builder and the read verb applied to it, so that distinct reads map to
 * distinct cache keys.
 *
 * The fingerprint combines the connection name, the compiled SQL, the
 * normalised bindings, the read verb, its arguments, and the registered eager
 * loads. Folding the verb and its arguments in is essential: read verbs such as
 * find(), value(), pluck(), and a column-projected get() apply their
 * constraints at execution time - after the base builder is compiled - so two
 * reads sharing one base builder (e.g. find(1) and find(2)) would otherwise
 * collide on a single cache key. Eager loads are likewise invisible to the
 * compiled base SQL, so with('posts')->get() would collide with a plain get().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryFingerprint
{
    /**
     * Build a fingerprint for the given prepared query builder and read verb.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return string
     */
    public static function for(Builder $query, string $method = '', array $arguments = []): string
    {
        $base = $query->getQuery();

        $components = [
            $base->getConnection()->getDatabaseName(),
            $base->toSql(),
            self::normalise($base->getBindings()),
            $method,
            self::normalise($arguments),
            self::normaliseEagerLoads($query),
        ];

        return hash('xxh128', implode('|', $components));
    }

    /**
     * Normalise a value list into a stable string representation, falling back
     * to serialisation when the values are not JSON-encodable.
     *
     * @param  array<int|string, mixed>  $values
     * @return string
     */
    private static function normalise(array $values): string
    {
        $normalised = array_map(fn (mixed $value): mixed => self::normaliseValue($value), $values);

        $encoded = json_encode($normalised);

        return $encoded === false ? serialize($normalised) : $encoded;
    }

    /**
     * Normalise the registered eager loads into a stable, order-independent
     * string representation.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @return string
     */
    private static function normaliseEagerLoads(Builder $query): string
    {
        $relations = $query instanceof EloquentBuilder ? array_keys($query->getEagerLoads()) : [];

        sort($relations);

        return implode(',', $relations);
    }

    /**
     * Normalise a single value into a comparable scalar form.
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
