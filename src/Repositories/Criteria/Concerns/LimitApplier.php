<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Apply a record limit to a query.
 *
 * Extracted from the monolithic ApiCriteria to encapsulate limit
 * application as a single-responsibility concern.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LimitApplier
{
    /**
     * Apply a record limit to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  int|null  $limit
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(Builder $query, ?int $limit): Builder
    {
        return is_null($limit) ? $query : $query->limit($limit);
    }
}
