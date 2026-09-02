<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
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
     * Assert that the engine answers the query through the full-text access
     * path rather than by reading the table.
     *
     * The access path is the whole point: this engine resolves a full-text
     * match against its index only where the match is not one branch of a
     * disjunction, and a match it cannot resolve that way is read row by row
     * while returning the same rows.
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
}
