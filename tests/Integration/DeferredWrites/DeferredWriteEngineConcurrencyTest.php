<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Concerns\CommitsRealTransactions;
use Tests\TestCase;

/**
 * Integration tests for a deferred-write flush that each engine's concurrency
 * error or a lost connection interrupts, inside and outside a caller's
 * transaction, provoked through a second connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
final class DeferredWriteEngineConcurrencyTest extends TestCase
{
    use CommitsRealTransactions;

    /** @var string The connection that competes with the flush */
    private const string CONCURRENT = 'concurrent';

    /** @var string The email the flush and the competitor both insert */
    private const string CONTESTED = 'contested@deferred.test';

    /**
     * Close the competing connection, releasing any lock it holds.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        DB::purge(self::CONCURRENT);

        parent::tearDown();
    }

    /**
     * Provide each flush strategy.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\FlushStrategy}>
     */
    public static function strategyProvider(): iterable
    {
        foreach (FlushStrategy::cases() as $strategy) {
            yield $strategy->value => [$strategy];
        }
    }

    /**
     * Provide each flush strategy and the records it keeps after a failed
     * chunk.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\FlushStrategy, int}>
     */
    public static function retentionProvider(): iterable
    {
        yield 'collect' => [FlushStrategy::COLLECT, 1];
        yield 'log' => [FlushStrategy::LOG, 0];
        yield 'throw' => [FlushStrategy::THROW, 1];
    }

    /**
     * Test that the MySQL lock wait timeout a flushed row raises inside a
     * transaction reaches the caller, so its retry writes each row once.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('strategyProvider')]
    #[Group('mysql')]
    public function testMySqlLockWaitTimeoutInsideATransactionReachesTheCallerAndItsRetryWritesEachRowOnce(FlushStrategy $strategy): void
    {
        $this->requireEngine('mysql');

        $concurrent = $this->holdTheContestedEmail();
        $pool       = new WritePool(chunkSize: 1, poolLimit: 100, strategy: $strategy);
        $attempts   = 0;

        DB::transaction(function () use ($concurrent, $pool, &$attempts): void {
            if (++$attempts === 2) {
                $concurrent->rollBack();
            }

            $this->deferTwoUsers($pool);
            $pool->flush();
        }, 2);

        self::assertSame(2, $attempts);
        self::assertSame(['first@deferred.test', self::CONTESTED], DB::table('users')->orderBy('id')->pluck('email')->all());
        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that the MySQL lock wait timeout a flushed row raises outside a
     * transaction is a chunk failure the strategy handles.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  int  $kept
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('retentionProvider')]
    #[Group('mysql')]
    public function testMySqlLockWaitTimeoutOutsideATransactionFollowsTheStrategy(FlushStrategy $strategy, int $kept): void
    {
        $this->requireEngine('mysql');

        $this->holdTheContestedEmail();

        $pool = new WritePool(chunkSize: 1, poolLimit: 100, strategy: $strategy);

        $this->deferTwoUsers($pool);

        try {
            $result = $pool->flush();
        } catch (WritePoolFlushException $exception) {
            $result = $exception->flushResult();
        }

        self::assertSame(1, $result->failureCount());
        self::assertSame(['first@deferred.test'], DB::table('users')->pluck('email')->all());
        self::assertSame($kept, $pool->count());
    }

    /**
     * Test that the PostgreSQL serialization failure a flushed row raises
     * inside a transaction reaches the caller and leaves nothing written or
     * buffered.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('strategyProvider')]
    #[Group('pgsql')]
    public function testPostgresSerializationFailureInsideATransactionReachesTheCallerAndLeavesNothingBuffered(FlushStrategy $strategy): void
    {
        $this->requireEngine('pgsql');

        $concurrent = $this->concurrent();
        $pool       = new WritePool(chunkSize: 1, poolLimit: 100, strategy: $strategy);

        try {
            DB::transaction(function () use ($concurrent, $pool): void {
                DB::unprepared('set transaction isolation level serializable');
                DB::table('users')->where('email', self::CONTESTED)->exists();

                $concurrent->transaction(static function (Connection $connection): void {
                    $connection->unprepared('set transaction isolation level serializable');
                    $connection->table('users')->where('email', self::CONTESTED)->exists();
                    $connection->table('users')->insert(['name' => 'Concurrent', 'email' => self::CONTESTED]);
                });

                $this->deferTwoUsers($pool);
                $pool->flush();
            });

            self::fail('Expected the serialization failure to be rethrown');
        } catch (DeadlockException $exception) {
            self::assertSame('40001', $exception->getPrevious()?->getCode());
        }

        self::assertSame(['Concurrent'], DB::table('users')->pluck('name')->all());
        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that a connection lost inside a transaction is rethrown by the flush
     * and leaves none of that connection's records buffered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Group('mysql')]
    #[Group('pgsql')]
    public function testALostConnectionInsideATransactionIsRethrownAndLeavesNoRecordsBuffered(): void
    {
        $this->requireEngine('mysql', 'pgsql');

        $concurrent = $this->concurrent();
        $pool       = new WritePool(chunkSize: 1, poolLimit: 100);

        /** @var \Throwable|null $rethrown */
        $rethrown = null;

        try {
            DB::transaction(function () use ($concurrent, $pool, &$rethrown): void {
                $this->deferTwoUsers($pool);
                $this->terminate($concurrent);

                try {
                    $pool->flush();
                } catch (\Throwable $exception) {
                    $rethrown = $exception;

                    throw $exception;
                }
            });
        } catch (\Throwable) {
            // The failure the flush rethrew is inspected below
        }

        self::assertInstanceOf(\Throwable::class, $rethrown);
        self::assertTrue((new LostConnectionDetector)->causedByLostConnection($rethrown));
        self::assertTrue($pool->isEmpty());
        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * Test that a connection lost outside a transaction reconnects and the
     * flush writes every row.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Group('mysql')]
    #[Group('pgsql')]
    public function testALostConnectionOutsideATransactionReconnectsAndWritesEveryRow(): void
    {
        $this->requireEngine('mysql', 'pgsql');

        $pool = new WritePool(chunkSize: 1, poolLimit: 100);

        $this->deferTwoUsers($pool);
        $this->terminate($this->concurrent());

        self::assertTrue($pool->flush()->isSuccessful());
        self::assertSame(['first@deferred.test', self::CONTESTED], DB::table('users')->orderBy('id')->pluck('email')->all());
    }

    /**
     * Defer a user, then one holding the contested email.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePool  $pool
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\WritePoolFlushException
     */
    private function deferTwoUsers(WritePool $pool): void
    {
        $pool->add(DB::getDefaultConnection(), 'users', ['name' => 'First', 'email' => 'first@deferred.test']);
        $pool->add(DB::getDefaultConnection(), 'users', ['name' => 'Contested', 'email' => self::CONTESTED]);
    }

    /**
     * Insert the contested email in an open transaction on the competing
     * connection, and wait at most a second for its lock.
     *
     * @return \Illuminate\Database\Connection
     *
     * @throws \Throwable
     */
    private function holdTheContestedEmail(): Connection
    {
        DB::unprepared('set session innodb_lock_wait_timeout = 1');

        $concurrent = $this->concurrent();

        $concurrent->beginTransaction();
        $concurrent->table('users')->insert(['name' => 'Concurrent', 'email' => self::CONTESTED]);

        return $concurrent;
    }

    /**
     * End the default connection's session from the competing connection.
     *
     * @param  \Illuminate\Database\Connection  $concurrent
     * @return void
     */
    private function terminate(Connection $concurrent): void
    {
        $pgsql   = DB::connection()->getDriverName() === 'pgsql';
        $session = DB::scalar($pgsql ? 'select pg_backend_pid()' : 'select connection_id()');

        assert(is_numeric($session));

        $concurrent->statement($pgsql ? sprintf('select pg_terminate_backend(%d, 5000)', $session) : sprintf('kill %d', $session));
    }

    /**
     * Open a second connection to the test database.
     *
     * @return \Illuminate\Database\Connection
     */
    private function concurrent(): Connection
    {
        Config::set('database.connections.' . self::CONCURRENT, Config::get('database.connections.' . DB::getDefaultConnection()));

        return DB::connection(self::CONCURRENT);
    }

    /**
     * Skip the test unless the suite runs on one of the given engines.
     *
     * @param  string  ...$drivers
     * @return void
     */
    private function requireEngine(string ...$drivers): void
    {
        if (in_array(DB::connection()->getDriverName(), $drivers, true)) {
            return;
        }

        self::markTestSkipped(sprintf('The failure is raised by %s only.', implode(' and ', $drivers)));
    }
}
