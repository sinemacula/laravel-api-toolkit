<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Concerns;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Concerns\TransactionConcern;
use Tests\TestCase;

/**
 * Tests for the TransactionConcern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(TransactionConcern::class)]
final class TransactionConcernTest extends TestCase
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

        $concern = new TransactionConcern;

        $result = $concern->wrap(fn (): string => 'committed', 1);

        self::assertSame('committed', $result);
    }

    /**
     * Test that wrap propagates exceptions thrown by $next (rolls back).
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public function testWrapRollsBackOnThrow(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn (\Closure $callback): mixed => $callback());

        $concern = new TransactionConcern;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback');

        $concern->wrap(fn (): never => throw new \RuntimeException('rollback'), 1);
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

        $concern = new TransactionConcern;

        $concern->wrap(fn (): bool => true, 5);
    }
}
