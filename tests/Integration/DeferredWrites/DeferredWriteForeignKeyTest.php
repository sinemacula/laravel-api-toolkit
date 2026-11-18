<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\Fixtures\Models\Project;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Integration tests for deferred rows that reference another table through a
 * foreign key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
final class DeferredWriteForeignKeyTest extends TestCase
{
    use CreatesDeferredWriteTargets;

    /** @var array<int, string> A failed insert aborts an enclosing PostgreSQL transaction, so none is opened */
    protected array $connectionsToTransact = [];

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();
    }

    /**
     * Provide each flush strategy, with the rows it retains and whether it
     * throws.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\FlushStrategy, int, bool}>
     */
    public static function strategyProvider(): iterable
    {
        yield 'collect' => [FlushStrategy::COLLECT, 1, false];
        yield 'throw' => [FlushStrategy::THROW, 1, true];
        yield 'log' => [FlushStrategy::LOG, 0, false];
    }

    /**
     * Test that a row whose parent is missing fails alone, and is retained or
     * dropped as the strategy says.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  int  $retained
     * @param  bool  $throws
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[DataProvider('strategyProvider')]
    public function testARowWithAMissingParentFailsAloneUnderEachStrategy(FlushStrategy $strategy, int $retained, bool $throws): void
    {
        $pool  = $this->bindPool($strategy);
        $owner = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $projects = $this->deferrableRepository(Project::class);

        $projects->defer(['user_id' => $owner->id, 'name' => 'kept']);
        $projects->defer(['user_id' => $owner->id + 1, 'name' => 'orphaned']);

        try {
            $result = $pool->flush();
            $thrown = false;
        } catch (WritePoolFlushException $exception) {
            $result = $exception->flushResult();
            $thrown = true;
        }

        self::assertSame($throws, $thrown);
        self::assertSame(1, $result->failureCount());
        self::assertSame($retained, $result->retainedRecordCount());
        self::assertSame($retained, $pool->count());
        self::assertSame(['kept'], DB::table('projects')->pluck('name')->all());
    }

    /**
     * Test that tables flush in the order their first row was deferred, so a
     * parent deferred first is written before the rows that reference it.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testAParentDeferredFirstFlushesBeforeItsChildren(): void
    {
        $pool = $this->bindPool(FlushStrategy::COLLECT);

        $this->deferrableRepository(User::class)->defer(['id' => 7, 'name' => 'Alice', 'email' => 'alice@example.com']);
        $this->deferrableRepository(Project::class)->defer(['user_id' => 7, 'name' => 'Apollo']);

        self::assertTrue($pool->flush()->isSuccessful());
        self::assertSame(['Apollo'], DB::table('projects')->pluck('name')->all());
    }

    /**
     * Test that a child deferred before its parent fails the flush that writes
     * the parent, and is written by the next one.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testAChildDeferredBeforeItsParentIsWrittenByTheNextFlush(): void
    {
        $pool = $this->bindPool(FlushStrategy::COLLECT);

        $this->deferrableRepository(Project::class)->defer(['user_id' => 7, 'name' => 'Apollo']);
        $this->deferrableRepository(User::class)->defer(['id' => 7, 'name' => 'Alice', 'email' => 'alice@example.com']);

        self::assertSame(1, $pool->flush()->failureCount());
        self::assertSame(1, DB::table('users')->count());
        self::assertSame(0, DB::table('projects')->count());

        self::assertTrue($pool->flush()->isSuccessful());
        self::assertSame(['Apollo'], DB::table('projects')->pluck('name')->all());
    }

    /**
     * Bind a write pool flushing one row per insert under the given strategy.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePool
     */
    private function bindPool(FlushStrategy $strategy): WritePool
    {
        $pool = new WritePool(1, 100, $strategy);

        $this->app?->instance(WritePool::class, $pool);

        return $pool;
    }
}
