<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Search;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\Drivers\EngineSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Fixture driver exposing the engine base with each engine-specific half named.
 *
 * The prefix and anywhere halves emit fragments that differ from one another
 * and from the equality match the base owns, and each reports a defect naming
 * itself, so the base's dispatch is provable without asserting through the
 * grammar of any one engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StubEngineSearchDriver extends EngineSearchDriver
{
    /** @var string The defect the prefix half reports */
    public const string PREFIX_DEFECT = 'prefix half';

    /** @var string The defect the anywhere half reports */
    public const string SUBSTRING_DEFECT = 'anywhere half';

    /**
     * Apply the prefix match for a single column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    #[\Override]
    protected function applyPrefixMatch(Builder $query, string $column, SearchTerm $term): Builder
    {
        return $query->orWhereRaw(sprintf('%s like ?', $this->wrap($query, $column)), [$term->pattern(SearchStrategy::PREFIX)]);
    }

    /**
     * Apply the anywhere-match for a single column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    #[\Override]
    protected function applySubstringMatch(Builder $query, string $column, SearchTerm $term): Builder
    {
        return $query->orWhereRaw(sprintf('instr(%s, ?) > 0', $this->wrap($query, $column)), [$term->value()]);
    }

    /**
     * Return what the column is missing before a prefix match can be served
     * from an index.
     *
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    #[\Override]
    protected function prefixIndexDefects(string $column, string $table, Connection $connection): array
    {
        return [self::PREFIX_DEFECT];
    }

    /**
     * Return what the column is missing before an anywhere-match can be served
     * from an index.
     *
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    #[\Override]
    protected function substringIndexDefects(string $column, string $table, Connection $connection): array
    {
        return [self::SUBSTRING_DEFECT];
    }
}
