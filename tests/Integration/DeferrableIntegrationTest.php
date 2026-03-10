<?php

namespace Tests\Integration;

use Carbon\Carbon;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Integration tests for the deferred writes feature.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Deferrable::class)]
class DeferrableIntegrationTest extends TestCase
{
    /** @var \Tests\Fixtures\Repositories\DeferrableUserRepository The repository instance under test. */
    private DeferrableUserRepository $repository;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->scoped(WritePool::class, fn (): WritePool => new WritePool(3, 5));

        $this->repository = $this->app->make(DeferrableUserRepository::class);
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Test that deferred inserts are not persisted until flush.
     *
     * @return void
     */
    public function testDeferredInsertsAreNotPersistedUntilFlush(): void
    {
        $this->repository->defer(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);

        static::assertSame(0, DB::table('users')->count());

        $this->repository->flushWrites();

        static::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that deferred inserts are flushed as bulk inserts.
     *
     * @return void
     */
    public function testDeferredInsertsAreFlushedAsBulkInserts(): void
    {
        $this->app->scoped(WritePool::class, fn (): WritePool => new WritePool(3, 10000));

        $this->repository = $this->app->make(DeferrableUserRepository::class);

        DB::enableQueryLog();

        for ($i = 0; $i < 6; $i++) {
            $this->repository->defer(['name' => "user_{$i}", 'email' => "user_{$i}@example.com", 'password' => 'secret']);
        }

        $this->repository->flushWrites();

        $queries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with($query['query'], 'insert'),
        );

        DB::disableQueryLog();

        static::assertSame(2, $queries->count());
        static::assertSame(6, DB::table('users')->count());
    }

    /**
     * Test that timestamps are captured at deferral time.
     *
     * @return void
     */
    public function testTimestampsAreCapturedAtDeferralTime(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:01'));

        $this->repository->defer(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);

        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:05'));

        $this->repository->flushWrites();

        $user = DB::table('users')->first();

        static::assertSame('2026-03-10 12:00:01', $user->created_at);
        static::assertSame('2026-03-10 12:00:01', $user->updated_at);
    }

    /**
     * Test that manual flush clears the pool and allows fresh accumulation.
     *
     * @return void
     */
    public function testManualFlushClearsPoolAndAllowsFreshAccumulation(): void
    {
        $this->repository->defer(['name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Dave', 'email' => 'dave@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        static::assertSame(2, DB::table('users')->count());

        $this->repository->defer(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        static::assertSame(3, DB::table('users')->count());
    }

    /**
     * Test that pool limit triggers auto-flush.
     *
     * @return void
     */
    public function testPoolLimitTriggersAutoFlush(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->defer(['name' => "user_{$i}", 'email' => "user_{$i}@example.com", 'password' => 'secret']);
        }

        static::assertSame(5, DB::table('users')->count());
    }

    /**
     * Test that RequestHandled event triggers flush.
     *
     * @return void
     */
    public function testRequestHandledEventTriggersFlush(): void
    {
        $this->repository->defer(['name' => 'Frank', 'email' => 'frank@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'secret']);

        Event::dispatch(new RequestHandled(new Request, new Response));

        static::assertSame(2, DB::table('users')->count());
    }

    /**
     * Test that CommandFinished event triggers flush.
     *
     * @return void
     */
    public function testCommandFinishedEventTriggersFlush(): void
    {
        $this->repository->defer(['name' => 'Heidi', 'email' => 'heidi@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Ivan', 'email' => 'ivan@example.com', 'password' => 'secret']);

        Event::dispatch(new CommandFinished('test:command', new ArrayInput([]), new NullOutput, 0));

        static::assertSame(2, DB::table('users')->count());
    }

    /**
     * Test that JobProcessed event triggers flush.
     *
     * @return void
     */
    public function testJobProcessedEventTriggersFlush(): void
    {
        $this->repository->defer(['name' => 'Judy', 'email' => 'judy@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Karl', 'email' => 'karl@example.com', 'password' => 'secret']);

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);

        Event::dispatch(new JobProcessed('sync', $job));

        static::assertSame(2, DB::table('users')->count());
    }

    /**
     * Test that JobFailed event triggers flush.
     *
     * @return void
     */
    public function testJobFailedEventTriggersFlush(): void
    {
        $this->repository->defer(['name' => 'Leo', 'email' => 'leo@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Mia', 'email' => 'mia@example.com', 'password' => 'secret']);

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);

        Event::dispatch(new JobFailed('sync', $job, new \RuntimeException('test')));

        static::assertSame(2, DB::table('users')->count());
    }

    /**
     * Test that WritePool is registered as a scoped singleton.
     *
     * @return void
     */
    public function testWritePoolIsRegisteredAsScopedSingleton(): void
    {
        $poolA = $this->app->make(WritePool::class);
        $poolB = $this->app->make(WritePool::class);

        static::assertSame($poolA, $poolB);
    }

    /**
     * Test that deferred writes config is available.
     *
     * @return void
     */
    public function testDeferredWritesConfigIsAvailable(): void
    {
        static::assertNotNull(Config::get('api-toolkit.deferred_writes.chunk_size'));
        static::assertNotNull(Config::get('api-toolkit.deferred_writes.pool_limit'));
    }

    /**
     * Test that existing repository operations are unaffected.
     *
     * @return void
     */
    public function testExistingRepositoryOperationsAreUnaffected(): void
    {
        $this->repository->create([
            'name'     => 'Nina',
            'email'    => 'nina@example.com',
            'password' => 'secret',
        ]);

        static::assertSame(1, DB::table('users')->count());
    }
}
