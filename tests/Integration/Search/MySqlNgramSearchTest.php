<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

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
     * Drop the index serving the anywhere-match on the searched column.
     *
     * @return void
     */
    #[\Override]
    protected function dropAnywhereMatchIndex(): void
    {
        DB::statement('alter table `users` drop index `users_name_ngram`');
    }

    /**
     * Recreate the index serving the anywhere-match on the searched column.
     *
     * @return void
     */
    #[\Override]
    protected function createAnywhereMatchIndex(): void
    {
        DB::statement('alter table `users` add fulltext index `users_name_ngram` (`name`) with parser ngram');
    }

    /**
     * Return the defect reported once that index is gone.
     *
     * @return string
     */
    #[\Override]
    protected function anywhereMatchDefect(): string
    {
        return 'Column "name" is declared searchable with the "substring" strategy, which needs a full-text index over that column '
            . 'alone on table "users", created with the ngram parser';
    }
}
