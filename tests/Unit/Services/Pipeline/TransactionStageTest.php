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
     * Test that wrap rolls back a write made by $next and rethrows its failure.
     *
     * @return void
     */
    public function testWrapRollsBackOnThrow(): void
    {
        $failure = new \RuntimeException('rollback');

        $this->assertThrows(static fn (): mixed => (new TransactionStage)->wrap(static function () use ($failure): never {
            DB::table('users')->insert(['name' => 'rolled back', 'email' => 'rolled-back@stage.test']);

            throw $failure;
        }, 1), \RuntimeException::class, 'rollback');

        $this->assertDatabaseCount('users', 0);
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
}
