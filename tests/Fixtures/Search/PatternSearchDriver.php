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
 * that it can prove nothing about the indexes behind them. The strategies it
 * implements, the verification claim it makes, and the defects that claim
 * reports are all constructor arguments, so a test can drive the applier's and
 * the schema validator's refusals as well as their happy paths.
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
     * @param  array<int, string>  $indexDefects
     * @param  string|null  $combinationDefect
     * @return void
     */
    public function __construct(

        /** The strategies this driver claims to implement, or null for every one */
        private ?array $strategies = null,

        /** Whether the driver claims it can prove a declared strategy is index backed */
        private bool $verifiesIndexBacking = false,

        /** The defects the driver reports for every column it is asked to prove */
        private array $indexDefects = [],

        /** The reason the driver gives for refusing the declared strategies together */
        private ?string $combinationDefect = null,
    ) {}

    /**
     * Return why the driver cannot resolve the given strategies from an index
     * when they are declared together, or null when it can.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $strategies
     * @return string|null
     */
    #[\Override]
    public function combinationDefect(array $strategies): ?string
    {
        return $this->combinationDefect;
    }

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
     * Return what the columns are missing before the strategy can be served
     * from an index on this connection, keyed by column.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    #[\Override]
    public function indexDefects(SearchStrategy $strategy, array $columns, string $table, Connection $connection): array
    {
        return $this->indexDefects === [] ? [] : array_fill_keys($columns, $this->indexDefects);
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
