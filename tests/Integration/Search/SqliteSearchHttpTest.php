<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Search\Drivers\SqliteSearchDriver;
use SineMacula\ApiToolkit\Search\RelevanceOrder;
use Tests\Fixtures\Models\User;

/**
 * HTTP search suite for the SQLite driver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SqliteSearchDriver::class)]
#[CoversClass(RelevanceOrder::class)]
#[CoversClass(OrderApplier::class)]
final class SqliteSearchHttpTest extends EngineSearchHttpTestCase
{
    /**
     * Test that a wildcard in the term ranks as itself, not as a wildcard.
     *
     * @return void
     */
    public function testAWildcardInTheTermRanksAsItself(): void
    {
        User::create(['name' => '500 Plan 50%', 'email' => 'plan@example.com', 'status' => 'active']);
        User::create(['name' => 'Plan 500', 'email' => 'five@example.com', 'status' => 'active']);
        User::create(['name' => '50% Off', 'email' => 'off@example.com', 'status' => 'active']);

        self::assertSame(['50% Off', '500 Plan 50%'], $this->names($this->getJson('/users?' . http_build_query(['search' => '50%', 'order' => 'relevance']))));
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'sqlite';
    }

    /**
     * Waive the index proof, which this engine cannot take.
     *
     * @return void
     */
    #[\Override]
    protected function serveSearches(): void
    {
        Config::set('api-toolkit.search.unverified_connections', [DB::connection()->getName()]);
    }
}
