<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

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
     * Drop the index serving the anywhere-match on the searched column.
     *
     * @return void
     */
    #[\Override]
    protected function dropAnywhereMatchIndex(): void
    {
        DB::statement('drop index users_name_trgm');
    }

    /**
     * Recreate the index serving the anywhere-match on the searched column.
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
