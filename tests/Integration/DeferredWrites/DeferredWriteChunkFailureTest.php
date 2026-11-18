<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Integration tests for a collecting flush whose middle chunk fails, at the top
 * level and inside an open transaction.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
final class DeferredWriteChunkFailureTest extends TestCase
{
    use CommitsRealTransactions;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->instance(WritePool::class, new WritePool(chunkSize: 2, poolLimit: 100));

        User::create(['name' => 'Taken', 'email' => 'taken@example.com']);
    }

    /**
     * Test that a failed chunk outside a transaction leaves the chunks around
     * it written, as the result reports.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testAFailedChunkOutsideATransactionLeavesTheOtherChunksWritten(): void
    {
        $this->assertTheTableMatches($this->deferAroundADuplicate()->flushWrites());
    }

    /**
     * Test that a failed chunk inside a transaction leaves it usable and the
     * chunks around it committed with it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testAFailedChunkInsideATransactionLeavesItUsable(): void
    {
        $result = DB::transaction(function (): WritePoolFlushResult {
            $result = $this->deferAroundADuplicate()->flushWrites();

            self::assertSame(5, DB::table('users')->count());

            return $result;
        });

        $this->assertTheTableMatches($result);
    }

    /**
     * Test that a failed chunk flushed by a transactional service leaves the
     * chunks around it committed with the service.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testAFailedChunkInsideATransactionalServiceLeavesTheOtherChunksCommitted(): void
    {
        $this->deferAroundADuplicate();

        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Flush the deferred users.
             *
             * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult
             *
             * @throws \Illuminate\Contracts\Container\BindingResolutionException
             */
            #[\Override]
            protected function handle(): WritePoolFlushResult
            {
                return Container::getInstance()->make(DeferrableUserRepository::class)->flushWrites();
            }
        };

        $this->assertTheTableMatches($service->runOrFail());
    }

    /**
     * Defer three chunks of two users, the middle one holding a taken email.
     *
     * @return \Tests\Fixtures\Repositories\DeferrableUserRepository
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function deferAroundADuplicate(): DeferrableUserRepository
    {
        $repository = $this->app?->make(DeferrableUserRepository::class);

        assert($repository instanceof DeferrableUserRepository);

        foreach (['A' => 'a', 'B' => 'b', 'Duplicate' => 'taken', 'C' => 'c', 'D' => 'd', 'E' => 'e'] as $name => $local) {
            $repository->defer(['name' => $name, 'email' => "{$local}@example.com"]);
        }

        return $repository;
    }

    /**
     * Assert the flush reported the middle chunk failed and the table holds
     * exactly the rows it reported written.
     *
     * @param  mixed  $result
     * @return void
     */
    private function assertTheTableMatches(mixed $result): void
    {
        self::assertInstanceOf(WritePoolFlushResult::class, $result);
        self::assertSame(2, $result->successCount());
        self::assertSame(1, $result->failureCount());
        self::assertSame(4, $result->flushedRecordCount());
        self::assertSame(['Taken', 'A', 'B', 'D', 'E'], DB::table('users')->orderBy('id')->pluck('name')->all());
    }
}
