<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;
use SineMacula\ApiToolkit\Search\RelevanceOrder;

/**
 * HTTP search suite for the PostgreSQL driver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PostgresTrigramSearchDriver::class)]
#[CoversClass(RelevanceOrder::class)]
#[CoversClass(OrderApplier::class)]
final class PostgresTrigramSearchHttpTest extends EngineSearchHttpTestCase
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
     * Index the article titles, the one searched surface the shared tables
     * leave unindexed.
     *
     * @return void
     */
    #[\Override]
    protected function serveSearches(): void
    {
        DB::statement('create index articles_title_trgm on articles using gin (title gin_trgm_ops)');
    }
}
