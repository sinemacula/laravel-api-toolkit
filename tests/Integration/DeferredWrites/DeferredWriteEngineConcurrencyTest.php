<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Illuminate\Database\Connection;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Concerns\CommitsRealTransactions;
use Tests\TestCase;

/**
 * Integration tests for a deferred-write flush inside a caller's transaction
 * that each engine's concurrency error or a lost connection ends, provoked
 * through a second connection.
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
     * Test that the MySQL lock wait timeout a flushed row raises reaches the
     * caller, so its retry writes each row once.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('strategyProvider')]
    #[Group('mysql')]
    public function testMySqlLockWaitTimeoutReachesTheCallerAndItsRetryWritesEachRowOnce(FlushStrategy $strategy): void
    {
        $this->requireEngine('mysql');

        DB::unprepared('set session innodb_lock_wait_timeout = 1');

        $concurrent = $this->concurrent();

        $concurrent->beginTransaction();
        $concurrent->table('users')->insert(['name' => 'Concurrent', 'email' => self::CONTESTED]);

        $this->assertTheRetryWritesEachRowOnce($strategy, static function (int $attempt) use ($concurrent): void {
            if ($attempt === 1) {
                return;
            }

            $concurrent->rollBack();
        });
    }

    /**
     * Test that the PostgreSQL serialization failure a flushed row raises
     * reaches the caller, so its retry writes each row once.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('strategyProvider')]
    #[Group('pgsql')]
    public function testPostgresSerializationFailureReachesTheCallerAndItsRetryWritesEachRowOnce(FlushStrategy $strategy): void
    {
        $this->requireEngine('pgsql');

        $concurrent = $this->concurrent();

        $this->assertTheRetryWritesEachRowOnce($strategy, static function (int $attempt) use ($concurrent): void {
            if ($attempt === 2) {
                $concurrent->table('users')->where('email', self::CONTESTED)->delete();

                return;
            }

            DB::unprepared('set transaction isolation level serializable');
            DB::table('users')->where('email', self::CONTESTED)->exists();

            $concurrent->transaction(static function (Connection $connection): void {
                $connection->unprepared('set transaction isolation level serializable');
                $connection->table('users')->where('email', self::CONTESTED)->exists();
                $connection->table('users')->insert(['name' => 'Concurrent', 'email' => self::CONTESTED]);
            });
        });
    }

    /**
     * Test that a connection lost mid-flush inside a transaction is rethrown
     * and leaves none of that connection's records buffered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Group('mysql')]
    #[Group('pgsql')]
    public function testALostConnectionMidFlushIsRethrownAndLeavesNoRecordsBuffered(): void
    {
        $this->requireEngine('mysql', 'pgsql');

        $concurrent = $this->concurrent();
        $pool       = new WritePool(chunkSize: 1, poolLimit: 100);

        /** @var \Throwable|null $rethrown */
        $rethrown = null;

        try {
            DB::transaction(function () use ($concurrent, $pool, &$rethrown): void {
                $pool->add(DB::getDefaultConnection(), 'users', ['name' => 'First', 'email' => 'first@deferred.test']);
                $pool->add(DB::getDefaultConnection(), 'users', ['name' => 'Second', 'email' => 'second@deferred.test']);

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
     * Defer an uncontested and a contested user inside a transaction with two
     * attempts and assert the first attempt's flush failed into the retry,
     * which wrote each row once.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  \Closure(int): void  $contend
     * @return void
     *
     * @throws \Throwable
     */
    private function assertTheRetryWritesEachRowOnce(FlushStrategy $strategy, \Closure $contend): void
    {
        $pool     = new WritePool(chunkSize: 1, poolLimit: 100, strategy: $strategy);
        $attempts = 0;

        DB::transaction(static function () use ($pool, $contend, &$attempts): void {
            $contend(++$attempts);

            $pool->add(DB::getDefaultConnection(), 'users', ['name' => 'First', 'email' => 'first@deferred.test']);
            $pool->add(DB::getDefaultConnection(), 'users', ['name' => 'Contested', 'email' => self::CONTESTED]);
            $pool->flush();
        }, 2);

        self::assertSame(2, $attempts);
        self::assertSame(['first@deferred.test', self::CONTESTED], DB::table('users')->orderBy('id')->pluck('email')->all());
        self::assertTrue($pool->isEmpty());
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
