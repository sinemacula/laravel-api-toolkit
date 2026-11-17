<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Pipeline\LockStage;
use SineMacula\ApiToolkit\Services\Pipeline\TransactionStage;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Services\LifecycleRecordingService;
use Tests\TestCase;

/**
 * Integration test for the transaction level and lock state each lifecycle hook
 * runs under, from a top-level run.
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
    use CommitsRealTransactions;

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
}
