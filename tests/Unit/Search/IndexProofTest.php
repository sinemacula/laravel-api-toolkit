<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\IndexProof;
use Tests\Fixtures\Search\CountingSearchDriver;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Tests for the per-process index proof memo.
 *
 * The driver counts how often it was asked, so the memo is proven by what
 * reaches the connection rather than by what comes back from it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexProof::class)]
final class IndexProofTest extends TestCase
{
    /**
     * Test that the defects the driver reports per column are flattened to the
     * distinct reasons behind them, so a reason the whole set carries is stated
     * once rather than once per column.
     *
     * @return void
     */
    public function testReportsEachDistinctReasonOnce(): void
    {
        $driver = new PatternSearchDriver(null, true, ['no index over the declared columns']);

        self::assertSame(
            ['no index over the declared columns'],
            IndexProof::defects($driver, SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $this->connection()),
        );
    }

    /**
     * Test that a proof with nothing to report comes back empty.
     *
     * @return void
     */
    public function testReportsNothingForADeclarationTheDriverProves(): void
    {
        self::assertSame(
            [],
            IndexProof::defects(new PatternSearchDriver(null, true), SearchStrategy::EXACT, ['id'], 'users', $this->connection()),
        );
    }

    /**
     * Test that the answer is memoised, so the catalogue is read once per
     * worker process rather than once per search.
     *
     * @return void
     */
    public function testMemoisesTheAnswerForTheSameDeclaration(): void
    {
        $driver     = new CountingSearchDriver;
        $connection = $this->connection();

        IndexProof::defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', $connection);
        IndexProof::defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', $connection);

        self::assertSame(1, $driver->calls);
    }

    /**
     * Test that a declaration differing in any part of its key is proved on its
     * own, so one table's answer never stands in for another's.
     *
     * @return void
     */
    public function testProvesEachDistinctDeclarationSeparately(): void
    {
        $driver     = new CountingSearchDriver;
        $connection = $this->connection();

        IndexProof::defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', $connection);
        IndexProof::defects($driver, SearchStrategy::SUBSTRING, ['name'], 'articles', $connection);
        IndexProof::defects($driver, SearchStrategy::PREFIX, ['name'], 'users', $connection);
        IndexProof::defects($driver, SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection);

        self::assertSame(4, $driver->calls);
    }

    /**
     * Test that clearing the memo makes the next proof read the connection
     * again, which is what a cache flush has to guarantee.
     *
     * @return void
     */
    public function testClearingTheMemoReadsTheConnectionAgain(): void
    {
        $driver     = new CountingSearchDriver;
        $connection = $this->connection();

        IndexProof::defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', $connection);

        IndexProof::clearCache();

        IndexProof::defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', $connection);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Return the connection the suite runs against.
     *
     * @return \Illuminate\Database\Connection
     */
    private function connection(): Connection
    {
        return DB::connection();
    }
}
