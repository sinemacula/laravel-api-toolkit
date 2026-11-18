<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Services\Pipeline\TransactionStage;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Fixtures\Services\TransactionRecordingService;
use Tests\TestCase;

/**
 * Integration tests for service transaction attempts on the concurrency errors
 * each engine raises, provoked through a second connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
#[CoversClass(TransactionStage::class)]
final class ServiceEngineConcurrencyTest extends TestCase
{
    use CommitsRealTransactions;

    /** @var string The connection that competes with the service */
    private const string CONCURRENT = 'concurrent';

    /** @var int The id of the row the service and the competitor both write */
    private int $id;

    /** @var int The number of attempts the service has made */
    private int $attempts = 0;

    /**
     * Insert the contested row.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->id = DB::table('users')->insertGetId(['name' => 'before', 'email' => 'contested@service.test']);
    }

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
     * Test that a single-attempt run fails with the PostgreSQL serialization
     * failure a concurrent update raises under repeatable read.
     *
     * @return void
     */
    #[Group('pgsql')]
    public function testPostgresSerializationFailureFailsASingleAttempt(): void
    {
        $this->requireEngine('pgsql');

        $service = new TransactionRecordingService($this->updateAfterAConcurrentUpdate());
        $result  = $service->run();

        self::assertTrue($result->failed());
        self::assertInstanceOf(QueryException::class, $result->exception);
        self::assertSame('40001', $result->exception->getCode());
        self::assertStringContainsString('could not serialize access due to concurrent update', $result->exception->getMessage());
        self::assertSame([1], $service->levels);
        self::assertSame('concurrent', $this->storedName());
    }

    /**
     * Test that a run with a second attempt retries the PostgreSQL
     * serialization failure and commits.
     *
     * @return void
     */
    #[Group('pgsql')]
    public function testPostgresSerializationFailureIsRetried(): void
    {
        $this->requireEngine('pgsql');

        $service = new TransactionRecordingService($this->updateAfterAConcurrentUpdate(), 2);

        self::assertTrue($service->run()->succeeded());
        self::assertSame([1, 1], $service->levels);
        self::assertSame('service', $this->storedName());
    }

    /**
     * Test that a single-attempt run fails with the MySQL lock wait timeout a
     * row held by another transaction raises.
     *
     * @return void
     */
    #[Group('mysql')]
    public function testMySqlLockWaitTimeoutFailsASingleAttempt(): void
    {
        $this->requireEngine('mysql');

        $service = new TransactionRecordingService($this->updateWhileAnotherTransactionHoldsTheRow());
        $result  = $service->run();

        self::assertTrue($result->failed());
        self::assertInstanceOf(QueryException::class, $result->exception);
        self::assertSame(1205, $result->exception->errorInfo[1] ?? null);
        self::assertStringContainsString('Lock wait timeout exceeded; try restarting transaction', $result->exception->getMessage());
        self::assertSame([1], $service->levels);
        self::assertSame('before', $this->storedName());
    }

    /**
     * Test that a run with a second attempt retries the MySQL lock wait timeout
     * once the other transaction releases the row.
     *
     * @return void
     */
    #[Group('mysql')]
    public function testMySqlLockWaitTimeoutIsRetriedOnceTheRowIsReleased(): void
    {
        $this->requireEngine('mysql');

        $service = new TransactionRecordingService($this->updateWhileAnotherTransactionHoldsTheRow(), 2);

        self::assertTrue($service->run()->succeeded());
        self::assertSame([1, 1], $service->levels);
        self::assertSame('service', $this->storedName());
    }

    /**
     * Build a body that reads the row under repeatable read and updates it,
     * after the competitor updates it on the first attempt.
     *
     * @return \Closure(): int
     */
    private function updateAfterAConcurrentUpdate(): \Closure
    {
        return function (): int {
            DB::unprepared('set transaction isolation level repeatable read');
            DB::table('users')->where('id', $this->id)->value('name');

            if (++$this->attempts === 1) {
                $this->concurrent()->table('users')->where('id', $this->id)->update(['name' => 'concurrent']);
            }

            return $this->updateName();
        };
    }

    /**
     * Build a body that updates the row while the competitor's transaction
     * holds it, releasing it on the second attempt.
     *
     * @return \Closure(): int
     */
    private function updateWhileAnotherTransactionHoldsTheRow(): \Closure
    {
        DB::unprepared('set session innodb_lock_wait_timeout = 1');

        $concurrent = $this->concurrent();

        $concurrent->beginTransaction();
        $concurrent->table('users')->where('id', $this->id)->lockForUpdate()->value('name');

        return function () use ($concurrent): int {
            if (++$this->attempts === 2) {
                $concurrent->commit();
            }

            return $this->updateName();
        };
    }

    /**
     * Update the row's name on the service's connection.
     *
     * @return int
     */
    private function updateName(): int
    {
        return DB::table('users')->where('id', $this->id)->update(['name' => 'service']);
    }

    /**
     * Return the row's committed name.
     *
     * @return mixed
     */
    private function storedName(): mixed
    {
        return DB::table('users')->where('id', $this->id)->value('name');
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
     * Skip the test unless the suite runs on the given engine.
     *
     * @param  string  $driver
     * @return void
     */
    private function requireEngine(string $driver): void
    {
        if (DB::connection()->getDriverName() === $driver) {
            return;
        }

        self::markTestSkipped(sprintf('The %s concurrency error is raised by its own engine only.', $driver));
    }
}
