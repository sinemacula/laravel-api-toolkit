<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteTransactionDetector;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\TestCase;

/**
 * Tests for the DeferredWriteTransactionDetector.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DeferredWriteTransactionDetector::class)]
final class DeferredWriteTransactionDetectorTest extends TestCase
{
    use CreatesDeferredWriteTargets;

    /**
     * Test that without a tracker any open transaction counts, the test's
     * wrapping one included.
     *
     * @return void
     */
    public function testWithoutATrackerEveryOpenTransactionCounts(): void
    {
        $this->useAuditConnection();

        $detector = new DeferredWriteTransactionDetector;

        self::assertTrue($detector->hasOpenTransaction('testing'));
        self::assertFalse($detector->hasOpenTransaction('audit'));

        DB::connection('audit')->transaction(function () use ($detector): void {
            self::assertTrue($detector->hasOpenTransaction('audit'));
        });
    }

    /**
     * Test that a resolver returning no tracker counts every open transaction.
     *
     * @return void
     */
    public function testAResolverReturningNoTrackerCountsEveryOpenTransaction(): void
    {
        $detector = new DeferredWriteTransactionDetector(fn (): null => null);

        self::assertTrue($detector->hasOpenTransaction('testing'));
    }

    /**
     * Test that the tracker discounts the test's wrapping transaction only.
     *
     * @return void
     */
    public function testTheTrackerDiscountsTheWrappingTransactionOnly(): void
    {
        $detector = new DeferredWriteTransactionDetector($this->transactionTracker(...));

        self::assertFalse($detector->hasOpenTransaction('testing'));

        DB::transaction(function () use ($detector): void {
            self::assertTrue($detector->hasOpenTransaction('testing'));
        });

        self::assertFalse($detector->hasOpenTransaction('testing'));
    }

    /**
     * Test that a transaction on another connection counts there and not on the
     * default connection.
     *
     * @return void
     */
    public function testATransactionCountsOnItsOwnConnectionOnly(): void
    {
        $this->useAuditConnection();

        $detector = new DeferredWriteTransactionDetector($this->transactionTracker(...));

        DB::connection('audit')->transaction(function () use ($detector): void {
            self::assertTrue($detector->hasOpenTransaction('audit'));
            self::assertFalse($detector->hasOpenTransaction('testing'));
        });

        self::assertFalse($detector->hasOpenTransaction('audit'));
    }

    /**
     * Test that a transaction the tracker never saw still counts as open.
     *
     * @return void
     */
    public function testATransactionTheTrackerDoesNotHoldCounts(): void
    {
        $this->useAuditConnection();

        $detector = new DeferredWriteTransactionDetector(fn (): DatabaseTransactionsManager => new DatabaseTransactionsManager);

        self::assertFalse($detector->hasOpenTransaction('audit'));

        DB::connection('audit')->transaction(function () use ($detector): void {
            self::assertTrue($detector->hasOpenTransaction('audit'));
        });
    }

    /**
     * Test that the tracker is resolved at each check, not once.
     *
     * @return void
     */
    public function testTheTrackerIsResolvedAtEachCheck(): void
    {
        $trackers = [$this->transactionTracker(), new DatabaseTransactionsManager];
        $detector = new DeferredWriteTransactionDetector(function () use (&$trackers): DatabaseTransactionsManager {
            return array_shift($trackers) ?? new DatabaseTransactionsManager;
        });

        self::assertFalse($detector->hasOpenTransaction('testing'));
        self::assertTrue($detector->hasOpenTransaction('testing'));
    }

    /**
     * Test that a connection whose configuration cannot be built holds no
     * transaction.
     *
     * @return void
     */
    public function testAConnectionThatCannotBeBuiltHoldsNoTransaction(): void
    {
        Config::set('database.connections.broken', ['driver' => 'sqlite', 'database' => ':memory:', 'read' => [], 'write' => false]);

        self::assertFalse((new DeferredWriteTransactionDetector)->hasOpenTransaction('broken'));
    }

    /**
     * Test that an unconfigured connection holds no transaction.
     *
     * @return void
     */
    public function testAnUnconfiguredConnectionHoldsNoTransaction(): void
    {
        self::assertFalse((new DeferredWriteTransactionDetector)->hasOpenTransaction('missing'));
    }

    /**
     * Provide failures and whether each ended the transaction it was raised in.
     *
     * @return iterable<string, array{\Throwable, bool}>
     */
    public static function failureProvider(): iterable
    {
        $lost = 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';

        yield 'deadlock' => [new DeadlockException('Deadlock found when trying to get lock'), true];
        yield 'lost connection' => [new QueryException('testing', 'insert', [], new \PDOException($lost)), true];
        yield 'lost connection warning' => [new \ErrorException('PDO::exec(): Error while sending QUERY packet'), true];
        yield 'constraint violation' => [new QueryException('testing', 'insert', [], new \PDOException('SQLSTATE[23000]: Integrity constraint violation')), false];
        yield 'warning' => [new \ErrorException('Undefined array key "name"'), false];
        yield 'lost connection outside the database' => [new \RuntimeException($lost), false];
    }

    /**
     * Test that only a nested concurrency error or a lost database connection
     * ends a transaction.
     *
     * @param  \Throwable  $failure
     * @param  bool  $ends
     * @return void
     */
    #[DataProvider('failureProvider')]
    public function testOnlyAConcurrencyErrorOrALostConnectionEndsATransaction(\Throwable $failure, bool $ends): void
    {
        self::assertSame($ends, (new DeferredWriteTransactionDetector)->endsTransaction($failure));
    }
}
