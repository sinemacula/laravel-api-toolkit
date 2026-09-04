<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;

/**
 * Search integration suite for the PostgreSQL driver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PostgresTrigramSearchDriver::class)]
final class PostgresTrigramSearchTest extends EngineSearchTestCase
{
    /**
     * Assert that the engine can answer the query from an index rather than by
     * reading the table.
     *
     * The planner costs a sequential scan against the table it has, and the
     * seeded table is small enough that a scan wins on cost whatever indexes
     * exist. Penalising the scan separates the question this asserts - whether
     * the emitted predicate is one an index can serve at all - from the size of
     * the table it was measured on. A predicate no index can serve is still
     * planned as a sequential scan with the penalty applied.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>  $query
     * @return void
     */
    #[\Override]
    protected function assertIndexBacked(Builder $query): void
    {
        DB::statement('set enable_seqscan = off');

        try {
            $plan = $this->plan($query, 'QUERY PLAN');
        } finally {
            DB::statement('set enable_seqscan = on');
        }

        self::assertStringNotContainsString('Seq Scan on users', $plan);
        self::assertStringContainsString('Index Scan', $plan);
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'pgsql';
    }

    /**
     * Drop the index serving the anywhere-match on the searched columns.
     *
     * @return void
     */
    #[\Override]
    protected function dropAnywhereMatchIndex(): void
    {
        DB::statement('drop index users_name_trgm');
    }

    /**
     * Recreate the index serving the anywhere-match on the searched columns.
     *
     * @return void
     */
    #[\Override]
    protected function createAnywhereMatchIndex(): void
    {
        DB::statement('create index users_name_trgm on users using gin (name gin_trgm_ops)');
    }

    /**
     * Return the defect reported once that index is gone.
     *
     * @return string
     */
    #[\Override]
    protected function anywhereMatchDefect(): string
    {
        return 'Column "name" is declared searchable with the "substring" strategy, which needs a trigram index over that column on table "users"';
    }
}
