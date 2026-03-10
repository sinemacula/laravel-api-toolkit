<?php

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\TestCase;

/**
 * Tests for the WritePool collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
class WritePoolTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool The write pool instance under test. */
    private WritePool $pool;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->pool = new WritePool(chunkSize: 500, poolLimit: 10000);
    }

    /**
     * Test that add buffers attributes for a given table.
     *
     * @return void
     */
    public function testAddBuffersAttributesForGivenTable(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        static::assertSame(1, $this->pool->count());
        static::assertFalse($this->pool->isEmpty());
    }

    /**
     * Test that add buffers attributes for multiple tables.
     *
     * @return void
     */
    public function testAddBuffersAttributesForMultipleTables(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('test_other', ['label' => 'baz']);

        static::assertSame(2, $this->pool->count());
    }

    /**
     * Test that count returns zero when the buffer is empty.
     *
     * @return void
     */
    public function testCountReturnsZeroWhenEmpty(): void
    {
        static::assertSame(0, $this->pool->count());
    }

    /**
     * Test that isEmpty returns true when no records are buffered.
     *
     * @return void
     */
    public function testIsEmptyReturnsTrueWhenNoRecordsBuffered(): void
    {
        static::assertTrue($this->pool->isEmpty());
    }

    /**
     * Test that isEmpty returns false after add.
     *
     * @return void
     */
    public function testIsEmptyReturnsFalseAfterAdd(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        static::assertFalse($this->pool->isEmpty());
    }

    /**
     * Test that flush inserts records into the database.
     *
     * @return void
     */
    public function testFlushInsertsRecordsIntoDatabase(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('test_records', ['name' => 'baz', 'value' => 'qux']);

        $this->pool->flush();

        static::assertSame(2, DB::table('test_records')->count());
    }

    /**
     * Test that flush groups records by table.
     *
     * @return void
     */
    public function testFlushGroupsRecordsByTable(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('test_other', ['label' => 'baz']);

        $this->pool->flush();

        static::assertSame(1, DB::table('test_records')->count());
        static::assertSame(1, DB::table('test_other')->count());
    }

    /**
     * Test that flush clears the buffer after execution.
     *
     * @return void
     */
    public function testFlushClearsBufferAfterExecution(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $this->pool->flush();

        static::assertSame(0, $this->pool->count());
        static::assertTrue($this->pool->isEmpty());
    }

    /**
     * Test that flush is a no-op when the buffer is empty.
     *
     * @return void
     */
    public function testFlushIsNoOpWhenBufferIsEmpty(): void
    {
        $this->pool->flush();

        static::assertSame(0, $this->pool->count());
    }

    /**
     * Test that flush chunks records by configured chunk size.
     *
     * @return void
     */
    public function testFlushChunksRecordsByConfiguredChunkSize(): void
    {
        $pool = new WritePool(chunkSize: 2, poolLimit: 10000);

        DB::enableQueryLog();

        for ($i = 0; $i < 5; $i++) {
            $pool->add('test_records', ['name' => "name_{$i}", 'value' => "value_{$i}"]);
        }

        $pool->flush();

        $queries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with($query['query'], 'insert'),
        );

        DB::disableQueryLog();

        static::assertCount(3, $queries);
        static::assertSame(5, DB::table('test_records')->count());
    }

    /**
     * Test that flush logs error and continues on chunk failure.
     *
     * @return void
     */
    public function testFlushLogsErrorAndContinuesOnChunkFailure(): void
    {
        $pool = new WritePool(chunkSize: 2, poolLimit: 10000);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'WritePool flush failed for table [nonexistent_table]')
                    && $context['table'] === 'nonexistent_table');

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        static::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that flush clears the buffer even after partial failure.
     *
     * @return void
     */
    public function testFlushClearsBufferEvenAfterPartialFailure(): void
    {
        Log::shouldReceive('error')->once()->withAnyArgs();

        $this->pool->add('nonexistent_table', ['col' => 'val']);

        $this->pool->flush();

        static::assertSame(0, $this->pool->count());
        static::assertTrue($this->pool->isEmpty());
    }

    /**
     * Test that add triggers auto-flush when pool limit is reached.
     *
     * @return void
     */
    public function testAddTriggersAutoFlushWhenPoolLimitReached(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 3);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);
        $pool->add('test_records', ['name' => 'c', 'value' => '3']);

        static::assertSame(3, DB::table('test_records')->count());
        static::assertSame(0, $pool->count());
    }

    /**
     * Test that add triggers auto-flush when pool limit is exceeded.
     *
     * @return void
     */
    public function testAddTriggersAutoFlushWhenPoolLimitExceeded(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);

        static::assertSame(2, DB::table('test_records')->count());
        static::assertSame(0, $pool->count());
    }

    /**
     * Test that subsequent adds after auto-flush accumulate in a fresh buffer.
     *
     * @return void
     */
    public function testSubsequentAddsAfterAutoFlushAccumulateInFreshBuffer(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);

        static::assertSame(0, $pool->count());

        $pool->add('test_records', ['name' => 'c', 'value' => '3']);

        static::assertSame(1, $pool->count());
        static::assertSame(2, DB::table('test_records')->count());
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('test_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('value');
        });

        Schema::create('test_other', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });
    }
}
