<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Search;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Fixture search driver emitting a plain predicate per declared column.
 *
 * Stands in for the development connection the suite runs against: it serves
 * every strategy with an equality or pattern comparison and, by default, admits
 * that it can prove nothing about the indexes behind them. Both the strategies
 * it implements and the verification claim it makes are constructor arguments,
 * so a test can drive the applier's refusals as well as its happy path.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class PatternSearchDriver implements SearchDriver
{
    /**
     * Constructor.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>|null  $strategies
     * @param  bool  $verifiesIndexBacking
     * @return void
     */
    public function __construct(

        /** The strategies this driver claims to implement, or null for every one */
        private ?array $strategies = null,

        /** Whether the driver claims it can prove a declared strategy is index backed */
        private bool $verifiesIndexBacking = false,
    ) {}

    /**
     * Return the match strategies this driver implements.
     *
     * @return array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>
     */
    #[\Override]
    public function supportedStrategies(): array
    {
        return $this->strategies ?? SearchStrategy::cases();
    }

    /**
     * Determine whether the driver can prove the strategy is index backed.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    #[\Override]
    public function canVerifyIndexBacking(SearchStrategy $strategy, Connection $connection): bool
    {
        return $this->verifiesIndexBacking;
    }

    /**
     * Apply the search predicate for the given columns to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return void
     */
    #[\Override]
    public function apply(Builder $query, array $columns, SearchTerm $term, SearchStrategy $strategy): void
    {
        $operator = $strategy->matchesByPattern() ? 'like' : '=';

        foreach ($columns as $column) {
            $query->orWhere($column, $operator, $term->pattern($strategy));
        }
    }
}
