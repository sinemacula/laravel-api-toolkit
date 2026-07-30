<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Pipeline;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Pipeline\TransactionStage;
use Tests\TestCase;

/**
 * Tests for the TransactionStage class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(TransactionStage::class)]
final class TransactionStageTest extends TestCase
{
    /**
     * Test that wrap returns $next's value when the transaction commits.
     *
     * @return void
     */
    public function testWrapCommitsOnSuccess(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn (\Closure $callback): mixed => $callback());

        $concern = new TransactionStage;

        $result = $concern->wrap(fn (): string => 'committed', 1);

        self::assertSame('committed', $result);
    }

    /**
     * Test that wrap propagates exceptions thrown by $next (rolls back).
     *
     * @return void
     */
    public function testWrapRollsBackOnThrow(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn (\Closure $callback): mixed => $callback());

        $concern = new TransactionStage;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback');

        $concern->wrap($this->throwRollback(...), 1);
    }

    /**
     * Test that wrap passes the supplied attempt count to DB::transaction.
     *
     * @return void
     */
    public function testWrapHonoursAttempts(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->withArgs(fn (\Closure $callback, int $attempts): bool => $attempts === 5)
            ->andReturnUsing(fn (\Closure $callback): mixed => $callback());

        $concern = new TransactionStage;

        $concern->wrap(fn (): bool => true, 5);
    }

    /**
     * A next-stage callback that always throws, driving the rollback path.
     *
     * @return never
     *
     * @throws \RuntimeException
     */
    private function throwRollback(): never
    {
        throw new \RuntimeException('rollback');
    }
}
