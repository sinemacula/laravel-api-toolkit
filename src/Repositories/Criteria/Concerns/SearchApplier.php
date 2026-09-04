<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\UnservableSearchException;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\IndexProofWaiver;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Search\SearchPlan;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Applies a free-text search term to an Eloquent query builder.
 *
 * The term is matched against the columns the root resource declared
 * searchable, and against those only: a text predicate carried into a
 * correlated subquery is the shape that turns one cheap request into a scan per
 * row, so a search never traverses a relation. Every declared column is OR-ed
 * within one nested group, which is then ANDed onto the query, so a search
 * narrows the result set no matter what the filter document does with its own
 * disjunctions.
 *
 * Nothing here decides what a match means. The connection's driver owns the
 * predicate, and this applier only refuses to ask for one the driver has said
 * it cannot serve from an index, or one the connection's own catalogue says no
 * index is behind. A resource that declared no searchable column is refused for
 * the same reason: answering a search with the unnarrowed table is the silent
 * failure the declaration exists to make impossible.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SearchApplier
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Search\SearchDriverRegistry  $drivers
     * @return void
     */
    public function __construct(

        /** Resolves the search driver serving the query's connection */
        private SearchDriverRegistry $drivers,
    ) {}

    /**
     * Apply the search term to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm|null  $term
     * @param  string|null  $resourceClass
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnservableSearchException
     */
    public function apply(Builder $query, ?SearchTerm $term, ?string $resourceClass): Builder
    {
        if ($term === null) {
            return $query;
        }

        $plan       = $this->resolvePlan($resourceClass);
        $model      = $query->getModel();
        $connection = $model->getConnection();
        $driver     = $this->drivers->resolve($connection->getDriverName());

        $this->assertServable($driver, $plan, $model->getTable(), $connection);

        $query->where(function (Builder $group) use ($driver, $plan, $term): void {
            foreach ($plan->strategies() as $strategy) {
                $group->orWhere(function (Builder $nested) use ($driver, $plan, $term, $strategy): void {
                    $driver->apply($nested, $plan->columnsFor($strategy), $term, $strategy);
                });
            }
        });

        return $query;
    }

    /**
     * Resolve the search plan for the resolved resource, refusing a resource
     * that declared nothing searchable.
     *
     * @param  string|null  $resourceClass
     * @return \SineMacula\ApiToolkit\Search\SearchPlan
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function resolvePlan(?string $resourceClass): SearchPlan
    {
        $plan = $resourceClass !== null && is_subclass_of($resourceClass, ApiResourceInterface::class)
            ? SearchPlan::for($resourceClass)
            : null;

        if ($plan === null || $plan->isEmpty()) {
            throw ValidationException::withMessages(['search' => 'The search parameter is not permitted for this resource.']);
        }

        return $plan;
    }

    /**
     * Assert that the driver serves the declared surface from an index on this
     * connection.
     *
     * The strategies are checked together first, since an engine may serve each
     * of them alone and none of them beside the others. Each is then proved
     * against the live catalogue, which is memoised for the life of the worker
     * process. A driver that cannot inspect the connection has proved nothing,
     * so it is refused unless the connection is one where the proof has been
     * waived - the development connection a suite runs against rather than
     * anything serving traffic.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  \SineMacula\ApiToolkit\Search\SearchPlan  $plan
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\UnservableSearchException
     */
    private function assertServable(SearchDriver $driver, SearchPlan $plan, string $table, Connection $connection): void
    {
        $name       = $connection->getDriverName();
        $strategies = $plan->strategies();
        $defect     = $driver->combinationDefect($strategies);

        if ($defect !== null) {
            throw UnservableSearchException::unservableCombination($name, $defect);
        }

        foreach ($strategies as $strategy) {
            $this->assertStrategyServable($driver, $strategy, $plan->columnsFor($strategy), $table, $connection);
        }
    }

    /**
     * Assert that the driver serves one declared strategy from an index on this
     * connection.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\UnservableSearchException
     */
    private function assertStrategyServable(SearchDriver $driver, SearchStrategy $strategy, array $columns, string $table, Connection $connection): void
    {
        $name = $connection->getDriverName();

        if (!in_array($strategy, $driver->supportedStrategies(), true)) {
            throw UnservableSearchException::unsupportedStrategy($name, $strategy);
        }

        if (!$driver->canVerifyIndexBacking($strategy, $connection)) {

            if (!IndexProofWaiver::waives($name)) {
                throw UnservableSearchException::unprovenIndexBacking($name, $strategy);
            }

            return;
        }

        $defects = IndexProof::defects($driver, $strategy, $columns, $table, $connection);

        if ($defects !== []) {
            throw UnservableSearchException::missingIndex($name, $strategy, $defects);
        }
    }
}
