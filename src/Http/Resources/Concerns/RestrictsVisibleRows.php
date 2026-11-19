<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Restricts the rows a resource exposes to the current request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RestrictsVisibleRows
{
    /**
     * Add the where clauses a row must match for the request to see it.
     *
     * Applied to every query the toolkit reads the resource through: the root,
     * its includes, relation filters and aggregates. Qualify each column, add
     * only wheres, and fail closed:
     * `$query->whereIn($query->qualifyColumn('workspace_id'), $request->user()?->workspaceIds() ?? [])`
     * matches nothing without a user, where `where($column, null)` would match
     * every row whose column is null.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @SuppressWarnings("php:S1172")
     */
    public static function restrictRows(Builder $query, Request $request): void {}
}
