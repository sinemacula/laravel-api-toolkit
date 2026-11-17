<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Database\Events\ConnectionEvent;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Pipeline\LockStage;
use SineMacula\ApiToolkit\Services\Pipeline\TransactionStage;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Services\LifecycleRecordingService;
use Tests\Fixtures\Services\TransactionRecordingService;
use Tests\TestCase;

/**
 * Integration test for the transactions a run begins, and the transaction level
 * and lock state each lifecycle hook runs under.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
#[CoversClass(LockStage::class)]
#[CoversClass(TransactionStage::class)]
final class ServiceLifecycleBoundariesTest extends TestCase
{
    use CommitsRealTransactions, RecordsStatements;

    /**
     * Test that authorize and validate run before the lock and transaction,
     * prepare and handle inside both, and afterCommit after both.
     *
     * @return void
     */
    public function testEachHookRunsInsideOrOutsideTheLockAndTransaction(): void
    {
        $service = new LifecycleRecordingService;

        self::assertTrue($service->run()->succeeded());
        self::assertSame([
            'authorize'   => ['level' => 0, 'lockFree' => true],
            'validate'    => ['level' => 0, 'lockFree' => true],
            'prepare'     => ['level' => 1, 'lockFree' => false],
            'handle'      => ['level' => 1, 'lockFree' => false],
            'afterCommit' => ['level' => 0, 'lockFree' => true],
        ], $service->seen);
        self::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that onFailure runs after the rollback and the lock release.
     *
     * @return void
     */
    public function testOnFailureRunsAfterTheRollbackAndTheLockRelease(): void
    {
        $service        = new LifecycleRecordingService;
        $service->fails = true;

        $result = $service->run();

        self::assertInstanceOf(ServiceExecutionException::class, $result->exception);
        self::assertSame(['level' => 1, 'lockFree' => false], $service->seen['handle']);
        self::assertSame(['level' => 0, 'lockFree' => true], $service->seen['onFailure']);
        self::assertArrayNotHasKey('afterCommit', $service->seen);
        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * Test that a service without a transaction runs handle() at level 0 and
     * keeps a write made before it throws.
     *
     * @return void
     */
    public function testNonTransactionalServiceKeepsAWriteMadeBeforeItThrows(): void
    {
        $service        = new LifecycleRecordingService(transactional: false);
        $service->fails = true;

        self::assertTrue($service->run()->failed());
        self::assertSame(['level' => 0, 'lockFree' => false], $service->seen['handle']);
        self::assertSame(['level' => 0, 'lockFree' => true], $service->seen['onFailure']);
        self::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that a top-level run begins and commits one transaction and runs no
     * statement besides the body's.
     *
     * @return void
     */
    public function testTopLevelRunBeginsAndCommitsOneTransaction(): void
    {
        [$transactions, $statements] = $this->recordRun($this->insertingService(transactional: true));

        self::assertSame(['begin:1', 'commit:0'], $transactions);
        self::assertStatementCount(1, $statements);
    }

    /**
     * Test that a run inside the caller's transaction adds one savepoint and
     * commits back to the caller's level.
     *
     * @return void
     */
    public function testNestedRunAddsOneSavepoint(): void
    {
        DB::beginTransaction();

        [$transactions, $statements] = $this->recordRun($this->insertingService(transactional: true));

        DB::commit();

        self::assertSame(['begin:2', 'commit:1'], $transactions);
        self::assertStatementCount(1, $statements);
    }

    /**
     * Test that a run with transactions disabled begins none.
     *
     * @return void
     */
    public function testNonTransactionalRunBeginsNoTransaction(): void
    {
        [$transactions, $statements] = $this->recordRun($this->insertingService(transactional: false));

        self::assertSame([], $transactions);
        self::assertStatementCount(1, $statements);
    }

    /**
     * Run the service, recording each transaction event with the level it left
     * and the SQL of every statement.
     *
     * @param  \Tests\Fixtures\Services\TransactionRecordingService  $service
     * @return array{list<string>, list<string>}
     */
    private function recordRun(TransactionRecordingService $service): array
    {
        $transactions = [];

        foreach (['begin' => TransactionBeginning::class, 'commit' => TransactionCommitted::class, 'rollback' => TransactionRolledBack::class] as $name => $event) {
            Event::listen($event, static function (ConnectionEvent $event) use ($name, &$transactions): void {
                $transactions[] = $name . ':' . $event->connection->transactionLevel();
            });
        }

        $statements = $this->recordStatements(static function () use ($service): void {
            self::assertTrue($service->run()->succeeded());
        });

        return [$transactions, $statements];
    }

    /**
     * Build a service whose body inserts one user.
     *
     * @param  bool  $transactional
     * @return \Tests\Fixtures\Services\TransactionRecordingService
     */
    private function insertingService(bool $transactional): TransactionRecordingService
    {
        return new TransactionRecordingService(static fn (): bool => DB::table('users')->insert([
            'name'  => 'Statement Recorder',
            'email' => 'statements@service.test',
        ]), transactional: $transactional);
    }
}
