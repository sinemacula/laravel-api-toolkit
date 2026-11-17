<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\Lockable;
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;
use SineMacula\ApiToolkit\Services\Pipeline\LockStage;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Concerns\CreatesCacheTables;
use Tests\Fixtures\Services\LockableService;
use Tests\TestCase;

/**
 * Integration test for service locks on each kind of cache store.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LockStage::class)]
#[CoversTrait(Lockable::class)]
final class ServiceLockStoreTest extends TestCase
{
    use CommitsRealTransactions, CreatesCacheTables;

    /** @var string The connection the database store keeps its locks on */
    private const string LOCK_CONNECTION = 'locks';

    /**
     * Provide the in-process, file and database stores.
     *
     * @return iterable<string, array{string}>
     */
    public static function storeProvider(): iterable
    {
        yield 'array store' => ['array'];
        yield 'file store' => ['file'];
        yield 'database store' => ['database'];
    }

    /**
     * Test that a lock held by another owner refuses the run until it is
     * released.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testLockHeldByAnotherOwnerRefusesTheRunUntilReleased(string $store): void
    {
        Config::set('cache.default', $store);

        $rival = Cache::lock((new LockableService)->getLockKey(), 60);

        self::assertTrue($rival->get());
        self::assertInstanceOf(LockUnavailableException::class, (new LockableService)->run()->exception);

        $rival->release();

        self::assertTrue((new LockableService)->run()->succeeded());
        self::assertTrue(self::isLockFree());
    }

    /**
     * Test that a nested run holds a lock kept outside the caller's transaction
     * until the caller rolls back, which cannot discard the lock row itself.
     *
     * @return void
     */
    public function testNestedRunReleasesALockOutsideTheCallerTransactionOnRollback(): void
    {
        Config::set('cache.default', 'database');

        DB::beginTransaction();

        self::assertTrue((new LockableService)->run()->succeeded());
        self::assertFalse(self::isLockFree());

        DB::rollBack();

        self::assertTrue(self::isLockFree());
    }

    /**
     * Create the database store's tables on its lock connection.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createCacheTables(self::LOCK_CONNECTION);
    }

    /**
     * Give the database store's locks a connection of their own.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('database.connections.' . self::LOCK_CONNECTION, $config->get('database.connections.testing'));
        $config->set('cache.stores.database.lock_connection', self::LOCK_CONNECTION);
    }

    /**
     * Determine whether another owner could take the service's lock now.
     *
     * @return bool
     */
    private static function isLockFree(): bool
    {
        return Cache::lock((new LockableService)->getLockKey(), 1)->get(static fn (): bool => true) === true;
    }
}
