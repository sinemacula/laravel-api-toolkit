<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Pipeline\TransactionStage;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceResult;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Services\RetrySafeRenameService;
use Tests\Fixtures\Services\TransactionRecordingService;
use Tests\TestCase;

/**
 * Integration test for service transaction attempts on a concurrency error, run
 * outside the test's wrapping transaction so the service owns level 1.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
#[CoversClass(TransactionStage::class)]
final class ServiceTransactionRetryTest extends TestCase
{
    /** @var array<int, string> No connection is wrapped, so services run their own top-level transaction */
    protected array $connectionsToTransact = [];

    /**
     * Roll back a transaction a failed commit left open, then clear the rows
     * the tests wrote.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        $pdo = DB::connection()->getPdo();

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        DB::table('users')->delete();

        parent::tearDown();
    }

    /**
     * Test that by default a concurrency error after a save fails the run once,
     * rolling back the write and skipping afterCommit().
     *
     * @return void
     */
    public function testDefaultRunFailsOnAConcurrencyErrorAfterASave(): void
    {
        Event::fake([ServiceFailed::class]);

        $deadlock = self::deadlock();
        $service  = new TransactionRecordingService(static function () use ($deadlock): never {
            $user        = new User;
            $user->name  = 'created';
            $user->email = 'created@service.test';
            $user->save();

            throw $deadlock;
        });

        $result = $this->runAtTopLevel($service);

        self::assertTrue($result->failed());
        self::assertSame($deadlock, $result->exception);
        self::assertSame([1], $service->levels);
        self::assertFalse($service->committed);
        self::assertSame(0, DB::table('users')->count());

        Event::assertDispatched(ServiceFailed::class, static fn (ServiceFailed $event): bool => $event->result === $result);
    }

    /**
     * Test that by default a concurrency error at commit fails the run once.
     *
     * @return void
     */
    public function testDefaultRunFailsOnAConcurrencyErrorAtCommit(): void
    {
        $deadlock = self::deadlock();

        Event::listen(TransactionCommitting::class, static function () use ($deadlock): never {
            throw $deadlock;
        });

        $service = new TransactionRecordingService(static fn (): string => 'done');
        $result  = $this->runAtTopLevel($service);

        self::assertTrue($result->failed());
        self::assertSame($deadlock, $result->exception);
        self::assertSame([1], $service->levels);
        self::assertFalse($service->committed);
    }

    /**
     * Test that a service meeting the retry contract retries a concurrency
     * error, writes the model it loaded by key, and runs its after-commit
     * callback once.
     *
     * @return void
     */
    public function testRetrySafeServiceRetriesAndPersistsItsWrite(): void
    {
        $id = DB::table('users')->insertGetId(['name' => 'before', 'email' => 'retry@service.test']);

        $service = new RetrySafeRenameService($id, self::deadlock());

        $result = $this->runAtTopLevel($service);

        self::assertTrue($result->succeeded());
        self::assertSame($id, $result->output());
        self::assertSame([1, 1], $service->levels);
        self::assertSame(1, $service->callbacks);
        self::assertSame('after', DB::table('users')->where('id', $id)->value('name'));
    }

    /**
     * Test that a service nested in an outer transaction does not retry, and
     * fails with a deadlock exception while the outer transaction stays open.
     *
     * @return void
     */
    public function testNestedRunDoesNotRetryAndFailsWithADeadlockException(): void
    {
        $deadlock = self::deadlock();
        $service  = new TransactionRecordingService(static fn (): never => throw $deadlock, 2);

        $result        = null;
        $levelAfterRun = null;

        DB::transaction(static function () use ($service, &$result, &$levelAfterRun): void {
            $result        = $service->run();
            $levelAfterRun = DB::transactionLevel();
        });

        self::assertInstanceOf(ServiceResult::class, $result);
        self::assertTrue($result->failed());
        self::assertInstanceOf(DeadlockException::class, $result->exception);
        self::assertSame($deadlock, $result->exception->getPrevious());
        self::assertSame([2], $service->levels);
        self::assertSame(1, $levelAfterRun);
        self::assertSame(0, DB::transactionLevel());
    }

    /**
     * Build the concurrency error MySQL raises for a deadlock.
     *
     * @return \Illuminate\Database\QueryException
     */
    private static function deadlock(): QueryException
    {
        $pdo = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');

        return new QueryException(DB::getDefaultConnection(), 'update users set name = ?', [], $pdo);
    }

    /**
     * Run the service from outside any transaction and return its result.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Input\ArrayInput, covariant mixed>  $service
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<mixed>
     */
    private function runAtTopLevel(Service $service): ServiceResult
    {
        self::assertSame(0, DB::transactionLevel());

        $result = $service->run();

        self::assertSame(0, DB::transactionLevel());

        return $result;
    }
}
