<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Services\LockableService;
use Tests\Fixtures\Services\NoTransactionService;
use Tests\Fixtures\Services\OutputService;
use Tests\Fixtures\Services\SimpleService;
use Tests\TestCase;

/**
 * Integration tests for the real-path service lifecycle with a real database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversClass(ServiceRunner::class)]
final class ServiceIntegrationTest extends TestCase
{
    /**
     * Test that the full lifecycle runs and returns the typed output.
     *
     * @return void
     */
    public function testSuccessfulLifecycleReturnsOutput(): void
    {
        $service = new OutputService(new ArrayInput(['message' => 'hello']));
        $result  = $service->run();

        self::assertTrue($result->succeeded());
        self::assertSame(['message' => 'hello'], $result->output());
        self::assertEmpty($result->sideEffectErrors());

        $simple  = new SimpleService;
        $result2 = $simple->run();

        self::assertTrue($result2->succeeded());
        self::assertTrue($simple->afterCommitCalled);
    }

    /**
     * Test that a failed handle() leaves no committed writes.
     *
     * @return void
     */
    public function testFailedHandleLeavesNoCommittedWrites(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Insert a row then throw to prove the transaction rolls back.
             *
             * @return never
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function handle(): never
            {
                DB::table('users')->insert([
                    'name'  => 'atomicity_test',
                    'email' => 'atomicity@test.com',
                ]);

                throw new \RuntimeException('rollback me');
            }
        };

        $result = $service->run();

        self::assertTrue($result->failed());
        self::assertDatabaseCount('users', 0);
    }

    /**
     * Test that a service with transactions disabled skips the transaction.
     *
     * @return void
     */
    public function testNoTransactionServiceSkipsTransaction(): void
    {
        $service = new NoTransactionService;
        $result  = $service->run();

        self::assertTrue($result->succeeded());
        self::assertTrue($service->afterCommitCalled);
    }

    /**
     * Test that a lockable service acquires and releases the lock.
     *
     * @return void
     */
    public function testLockableServiceAcquiresAndReleasesLock(): void
    {
        $result1 = (new LockableService)->run();
        self::assertTrue($result1->succeeded());

        // Hold the lock to simulate a contended run
        $lockKey = (new LockableService)->getLockKey();
        $held    = Cache::lock($lockKey, 60);
        self::assertTrue($held->get());

        try {
            $result2 = (new LockableService)->run();
            self::assertTrue($result2->failed());
            self::assertInstanceOf(LockUnavailableException::class, $result2->exception);
        } finally {
            $held->release();
        }

        $result3 = (new LockableService)->run();
        self::assertTrue($result3->succeeded());
    }
}
