<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Pipeline\LockStage;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Concerns\CreatesCacheTables;
use Tests\Fixtures\Services\LifecycleRecordingService;
use Tests\Fixtures\Services\LockableService;
use Tests\TestCase;

/**
 * Integration test for when a service releases its cache lock.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
#[CoversClass(LockStage::class)]
final class ServiceLockReleaseTest extends TestCase
{
    use CommitsRealTransactions, CreatesCacheTables;

    /**
     * Provide the array store and the database store on the default connection,
     * where the lock row joins the caller's transaction.
     *
     * @return iterable<string, array{string}>
     */
    public static function storeProvider(): iterable
    {
        yield 'array store' => ['array'];
        yield 'database store' => ['database'];
    }

    /**
     * Test that a throwing handle() releases the lock and fails the result.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testThrowingHandleReleasesLock(string $store): void
    {
        Config::set('cache.default', $store);

        $service = $this->failingLockableService();

        $result = $service->run();

        self::assertTrue($result->failed());
        self::assertInstanceOf(\RuntimeException::class, $result->exception);
        self::assertTrue(self::isLockFree($service));
    }

    /**
     * Test that a run inside a caller's transaction holds the lock until the
     * caller commits.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testNestedRunHoldsTheLockUntilTheCallerCommits(string $store): void
    {
        Config::set('cache.default', $store);

        $service = new LockableService;

        DB::transaction(static function () use ($service): void {
            self::assertTrue($service->run()->succeeded());
            self::assertFalse(self::isLockFree($service));
        });

        self::assertTrue(self::isLockFree($service));
    }

    /**
     * Test that a run inside a caller's transaction holds the lock until the
     * caller rolls back.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testNestedRunHoldsTheLockUntilTheCallerRollsBack(string $store): void
    {
        Config::set('cache.default', $store);

        $service = new LockableService;

        DB::beginTransaction();

        self::assertTrue($service->run()->succeeded());
        self::assertFalse(self::isLockFree($service));

        DB::rollBack();

        self::assertTrue(self::isLockFree($service));
    }

    /**
     * Test that a failing run inside a caller's transaction releases the lock
     * at once.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testFailingNestedRunReleasesTheLockAtOnce(string $store): void
    {
        Config::set('cache.default', $store);

        $service = $this->failingLockableService();

        DB::transaction(static function () use ($service): void {
            self::assertTrue($service->run()->failed());
            self::assertTrue(self::isLockFree($service));
        });
    }

    /**
     * Test that a second run with the same lock key inside one caller's
     * transaction cannot take the lock.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testSecondRunWithTheSameKeyInOneCallerTransactionIsRefused(string $store): void
    {
        Config::set('cache.default', $store);

        $second = null;

        DB::transaction(static function () use (&$second): void {
            self::assertTrue((new LockableService)->run()->succeeded());

            $second = (new LockableService)->run();
        });

        self::assertNotNull($second);
        self::assertInstanceOf(LockUnavailableException::class, $second->exception);
    }

    /**
     * Test that a run whose committed savepoint is rolled back by an enclosing
     * transaction releases the lock then, and runs no side effects when the
     * outermost transaction commits.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testRolledBackParentReleasesTheLockAndDiscardsTheSideEffects(string $store): void
    {
        Config::set('cache.default', $store);

        $completed = 0;

        Event::listen(ServiceCompleted::class, static function () use (&$completed): void {
            $completed++;
        });

        $service = new LifecycleRecordingService;

        DB::transaction(static function () use ($service): void {
            DB::beginTransaction();

            DB::transaction(static function () use ($service): void {
                self::assertTrue($service->run()->succeeded());
            });

            self::assertFalse(self::isLockFree($service));

            DB::rollBack();

            self::assertTrue(self::isLockFree($service));
        });

        self::assertArrayNotHasKey('afterCommit', $service->seen);
        self::assertSame(0, $completed);
        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * Test that a run outliving its lock does not release the lock another
     * owner took after it expired.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testExpiredLockReleaseDoesNotFreeAnotherOwner(string $store): void
    {
        Config::set('cache.default', $store);

        $service = new class (new ArrayInput([])) extends Service {
            /** @var \Illuminate\Contracts\Cache\Lock|null The lock another owner took */
            public ?Lock $rival = null;

            /** @var bool Whether this service acquires a cache lock */
            protected bool $lockable = true;

            /** @var int Cache lock expiration in seconds */
            protected int $lockExpiration = 5;

            /**
             * Outlive the lock, then let another owner take it.
             *
             * @return bool
             */
            #[\Override]
            protected function handle(): bool
            {
                Carbon::setTestNow(Carbon::now()->addSeconds(6));

                $this->rival = Cache::lock($this->getLockKey(), 60);

                return $this->rival->get() === true;
            }

            /**
             * Return the unique lock identity for this invocation.
             *
             * @return string
             */
            #[\Override]
            protected function lockId(): string
            {
                return 'expiring';
            }
        };

        self::assertTrue($service->run()->output());
        self::assertFalse(self::isLockFree($service));

        $service->rival?->release();
    }

    /**
     * Create the database cache store's tables.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createCacheTables();
    }

    /**
     * Determine whether another owner could take the service's lock now.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>  $service
     * @return bool
     */
    private static function isLockFree(Service $service): bool
    {
        return Cache::lock($service->getLockKey(), 1)->get(static fn (): bool => true) === true;
    }

    /**
     * Build a lockable service whose handle() throws.
     *
     * @return \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, mixed>
     */
    private function failingLockableService(): Service
    {
        return new class (new ArrayInput([])) extends Service {
            /** @var bool Whether this service acquires a cache lock */
            protected bool $lockable = true;

            /**
             * Throw from the core to force the lock to release under failure.
             *
             * @return never
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function handle(): never
            {
                throw new \RuntimeException('handle failed under lock');
            }

            /**
             * Return the unique lock identity for this invocation.
             *
             * @return string
             */
            #[\Override]
            protected function lockId(): string
            {
                return 'lock-release-on-throw';
            }
        };
    }
}
