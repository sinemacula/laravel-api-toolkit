<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Search;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Search\Drivers\EngineSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Fixture driver naming indexes the engine would refuse to plan against.
 *
 * The base drops such an index before any strategy is proved against it, and
 * the names are supplied rather than read so the dropping is provable without
 * an engine behind it. Only the equality match matters here, so the two
 * engine-specific halves report nothing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StubFilteringSearchDriver extends EngineSearchDriver
{
    /**
     * Constructor.
     *
     * @param  array<int, string>  $unusable
     * @return void
     */
    public function __construct(

        /** @var array<int, string> The indexes the engine would refuse to plan against */
        private readonly array $unusable = [],
    ) {}

    /**
     * Apply the prefix match for the declared columns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return void
     */
    #[\Override]
    protected function applyPrefixMatch(Builder $query, array $columns, SearchTerm $term): void {}

    /**
     * Apply the anywhere match for the declared columns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return void
     */
    #[\Override]
    protected function applySubstringMatch(Builder $query, array $columns, SearchTerm $term): void {}

    /**
     * Return what the columns are missing before a prefix match is served.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function prefixIndexDefects(array $columns, string $table, Connection $connection): array
    {
        return [];
    }

    /**
     * Return what the columns are missing before an anywhere match is served.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function substringIndexDefects(array $columns, string $table, Connection $connection): array
    {
        return [];
    }

    /**
     * Return the names of indexes the engine would refuse to plan against.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    #[\Override]
    protected function unusableIndexNames(string $table, Connection $connection): array
    {
        return $this->unusable;
    }
}
