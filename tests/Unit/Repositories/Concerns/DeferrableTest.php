<?php

namespace Tests\Unit\Repositories\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Tests for the Deferrable trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Deferrable::class)]
class DeferrableTest extends TestCase
{
    private const TIMESTAMP_DEFERRAL = '2026-03-10 12:00:01';
    private const TIMESTAMP_EXPLICIT = '2026-03-10 09:00:00';

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

        $this->app->scoped(WritePool::class, fn (): WritePool => new WritePool(500, 10000)); // @phpstan-ignore method.nonObject

        $this->repository = $this->app->make(DeferrableUserRepository::class); // @phpstan-ignore method.nonObject
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
     * Test that defer buffers attributes without executing a database query.
     *
     * @return void
     */
    public function testDeferBuffersAttributesWithoutExecutingDatabaseQuery(): void
    {
        $this->repository->defer(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);

        static::assertSame(0, DB::table('users')->count());
    }

    /**
     * Test that defer captures timestamps at deferral time.
     *
     * @return void
     */
    public function testDeferCapturesTimestampsAtDeferralTime(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TIMESTAMP_DEFERRAL));

        $this->repository->defer(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);

        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:05'));

        $this->repository->flushWrites();

        $user = DB::table('users')->first();

        static::assertNotNull($user);
        static::assertSame(self::TIMESTAMP_DEFERRAL, $user->created_at);
        static::assertSame(self::TIMESTAMP_DEFERRAL, $user->updated_at);
    }

    /**
     * Test that defer preserves explicit timestamps.
     *
     * @return void
     */
    public function testDeferPreservesExplicitTimestamps(): void
    {
        $this->repository->defer([
            'name'       => 'Carol',
            'email'      => 'carol@example.com',
            'password'   => 'secret',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-06-01 00:00:00',
        ]);

        $this->repository->flushWrites();

        $user = DB::table('users')->first();

        static::assertNotNull($user);
        static::assertSame('2025-01-01 00:00:00', $user->created_at);
        static::assertSame('2025-06-01 00:00:00', $user->updated_at);
    }

    /**
     * Test that defer adds created_at and updated_at when missing.
     *
     * @return void
     */
    public function testDeferAddsCreatedAtAndUpdatedAtWhenMissing(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TIMESTAMP_EXPLICIT));

        $this->repository->defer(['name' => 'Dave', 'email' => 'dave@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        $user = DB::table('users')->first();

        static::assertNotNull($user);
        static::assertSame(self::TIMESTAMP_EXPLICIT, $user->created_at);
        static::assertSame(self::TIMESTAMP_EXPLICIT, $user->updated_at);
    }

    /**
     * Test that flushWrites persists all deferred records.
     *
     * @return void
     */
    public function testFlushWritesPersistsAllDeferredRecords(): void
    {
        $this->repository->defer(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Frank', 'email' => 'frank@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        static::assertSame(2, DB::table('users')->count());
    }

    /**
     * Test that flushWrites clears the pool.
     *
     * @return void
     */
    public function testFlushWritesClearsThePool(): void
    {
        $this->repository->defer(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();
        $this->repository->flushWrites();

        static::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that defer uses the model table name.
     *
     * @return void
     */
    public function testDeferUsesModelTableName(): void
    {
        $this->repository->defer(['name' => 'Heidi', 'email' => 'heidi@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        static::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that multiple defer calls accumulate in the pool.
     *
     * @return void
     */
    public function testMultipleDeferCallsAccumulateInPool(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->defer(['name' => "user_{$i}", 'email' => "user_{$i}@example.com", 'password' => 'secret']);
        }

        static::assertSame(0, DB::table('users')->count());

        $this->repository->flushWrites();

        static::assertSame(5, DB::table('users')->count());
    }
}
