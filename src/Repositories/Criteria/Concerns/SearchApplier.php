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
 * Never traverses a relation: a text predicate in a subquery scans per row.
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
     * @param  \SineMacula\ApiToolkit\Search\IndexProof  $indexProof
     * @return void
     */
    public function __construct(

        /** Resolves the search driver serving the query's connection */
        private SearchDriverRegistry $drivers,

        /** Proves each declared strategy against the connection's catalogue */
        private IndexProof $indexProof,
    ) {}

    /**
     * Apply the search term to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm|null  $term
     * @param  string|null  $resourceClass
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
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
     * Resolve the resource's search plan, refusing one with nothing searchable.
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
     * Assert that the driver serves the declared surface from an index.
     *
     * Strategies are checked together first, as an engine may not combine them.
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
     * Assert that the driver serves one strategy from an index.
     *
     * An unverifiable driver is refused unless the connection waives the proof.
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
        $name           = $connection->getDriverName();
        $connectionName = $connection->getName();

        if (!in_array($strategy, $driver->supportedStrategies(), true)) {
            throw UnservableSearchException::unsupportedStrategy($name, $strategy);
        }

        if (!$driver->canVerifyIndexBacking($strategy, $connection)) {

            if ($connectionName === null) {
                throw UnservableSearchException::unnamedConnection($strategy);
            }

            if (!IndexProofWaiver::waives($connectionName)) {
                throw UnservableSearchException::unprovenIndexBacking($connectionName, $strategy);
            }

            return;
        }

        $defects = $this->indexProof->defects($driver, $strategy, $columns, $table, $connection);

        if ($defects !== []) {
            throw UnservableSearchException::missingIndex($connectionName ?? $name, $strategy, $defects);
        }
    }
}
