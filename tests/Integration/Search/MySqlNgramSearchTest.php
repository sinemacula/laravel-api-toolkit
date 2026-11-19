<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;

/**
 * Search integration suite for the MySQL driver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MySqlNgramSearchDriver::class)]
#[Group('mysql')]
final class MySqlNgramSearchTest extends EngineSearchTestCase
{
    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'mysql';
    }

    /**
     * Return the rows the anywhere-match finds for "ann smith".
     *
     * The n-gram phrase matches the words' tokens in order, whatever whitespace
     * separates them.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function multiWordMatches(): array
    {
        return ['Ann Smith', 'ANN SMITH', 'Ann  Smith'];
    }

    /**
     * Return, keyed by term, the rows each accented or upper-case term finds.
     *
     * The column's accent- and case-insensitive collation folds both.
     *
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function foldedMatches(): array
    {
        return [
            'élise' => ['Élise Müller', 'Elise Martin'],
            'ÉLISE' => ['Élise Müller', 'Elise Martin'],
            'ELISE' => ['Élise Müller', 'Elise Martin'],
        ];
    }

    /**
     * Return the rows an equality match finds for "smith" beside "Smith".
     *
     * The column's case-insensitive collation folds the comparison.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function caseFoldedEqualityMatches(): array
    {
        return ['Smith'];
    }

    /**
     * Assert that the engine answers the query through its full-text index.
     *
     * This engine resolves a full-text match from its index only outside a
     * disjunction; otherwise it reads row by row and returns the same rows.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>  $query
     * @return void
     */
    #[\Override]
    protected function assertIndexBacked(Builder $query): void
    {
        self::assertSame('fulltext', $this->plan($query, 'type'));
    }

    /**
     * Drop the index serving the anywhere-match on the searched columns.
     *
     * @return void
     */
    #[\Override]
    protected function dropAnywhereMatchIndex(): void
    {
        DB::statement('alter table `users` drop index `users_search_ngram`');
    }

    /**
     * Recreate the index serving the anywhere-match on the searched columns.
     *
     * @return void
     */
    #[\Override]
    protected function createAnywhereMatchIndex(): void
    {
        DB::statement('alter table `users` add fulltext index `users_search_ngram` (`name`, `email`) with parser ngram');
    }

    /**
     * Return the defect reported once that index is gone.
     *
     * @return string
     */
    #[\Override]
    protected function anywhereMatchDefect(): string
    {
        return 'The columns declared with the "substring" strategy ("name", "email") are matched together, '
            . 'so table "users" needs one full-text index over exactly that column list, created with the ngram parser';
    }

    /**
     * Rebuild the full-text index over the same columns with the default
     * parser, which matches whole words only.
     *
     * @return void
     */
    #[\Override]
    protected function makeAnywhereMatchIndexUnusable(): void
    {
        $this->dropAnywhereMatchIndex();

        DB::statement('alter table `users` add fulltext index `users_search_ngram` (`name`, `email`)');
    }

    /**
     * Rebuild the full-text index with the n-gram parser.
     *
     * @return void
     */
    #[\Override]
    protected function makeAnywhereMatchIndexUsable(): void
    {
        $this->dropAnywhereMatchIndex();
        $this->createAnywhereMatchIndex();
    }

    /**
     * Drop the index serving the leading match on the searched column.
     *
     * This engine reads a leading match from an ordinary index, here the unique
     * one.
     *
     * @return void
     */
    #[\Override]
    protected function dropPrefixMatchIndex(): void
    {
        DB::statement('alter table `users` drop index `users_email_unique`');
    }

    /**
     * Recreate the index serving the leading match on the searched column.
     *
     * @return void
     */
    #[\Override]
    protected function createPrefixMatchIndex(): void
    {
        DB::statement('alter table `users` add unique index `users_email_unique` (`email`)');
    }

    /**
     * Return the defect the leading match draws once that index is gone.
     *
     * @return string
     */
    #[\Override]
    protected function prefixMatchDefect(): string
    {
        return 'Column "email" is declared searchable with the "prefix" strategy, '
            . 'which needs an index leading with that column on table "users"';
    }

    /**
     * Drop the ordinary index serving the equality match.
     *
     * @return void
     */
    #[\Override]
    protected function dropEqualityMatchIndex(): void
    {
        DB::statement('alter table `users` drop index `users_name_index`');
    }

    /**
     * Recreate the ordinary index serving the equality match.
     *
     * @return void
     */
    #[\Override]
    protected function createEqualityMatchIndex(): void
    {
        DB::statement('alter table `users` add index `users_name_index` (`name`)');
    }

    /**
     * Hide the ordinary index from the planner, keeping it in the catalogue.
     *
     * @return void
     */
    #[\Override]
    protected function makeEqualityMatchIndexUnusable(): void
    {
        DB::statement('alter table `users` alter index `users_name_index` invisible');
    }

    /**
     * Return the ordinary index to the planner.
     *
     * @return void
     */
    #[\Override]
    protected function makeEqualityMatchIndexUsable(): void
    {
        DB::statement('alter table `users` alter index `users_name_index` visible');
    }
}
