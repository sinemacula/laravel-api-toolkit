<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fixture trait declaring a protected attribute scope over a start time.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ScopesByStart
{
    /**
     * Scope the query to rows starting within the given bounds.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $from
     * @param  string  $until
     * @return void
     */
    #[Scope]
    protected function startingBetween(Builder $query, string $from, string $until): void
    {
        $query->whereBetween('starts_at', [$from, $until]);
    }
}
