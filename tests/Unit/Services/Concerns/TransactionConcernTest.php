<?php

namespace Tests\Unit\Services\Concerns;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Concerns\TransactionConcern;
use SineMacula\ApiToolkit\Services\Service;
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
class TransactionConcernTest extends TestCase
{
    /**
     * Test that execute wraps the next closure in a database transaction.
     *
     * @return void
     */
    public function testExecuteWrapsNextInTransaction(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->withArgs(function (\Closure $callback, int $retries): bool {
                return $retries === 3;
            })
            ->andReturnUsing(function (\Closure $callback): bool {
                return $callback();
            });

        $concern = new TransactionConcern;
        $service = $this->createMock(Service::class);

        $result = $concern->execute($service, fn (): bool => true);

        static::assertTrue($result);
    }

    /**
     * Test that execute returns false when the next closure returns false.
     *
     * @return void
     */
    public function testExecuteReturnsFalseWhenNextReturnsFalse(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function (\Closure $callback): bool {
                return $callback();
            });

        $concern = new TransactionConcern;
        $service = $this->createMock(Service::class);

        $result = $concern->execute($service, fn (): bool => false);

        static::assertFalse($result);
    }

    /**
     * Test that execute propagates exceptions thrown by the next closure.
     *
     * @return void
     */
    public function testExecutePropagatesExceptionFromNext(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function (\Closure $callback): bool {
                return $callback();
            });

        $concern = new TransactionConcern;
        $service = $this->createMock(Service::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fail');

        $concern->execute($service, fn (): bool => throw new \RuntimeException('fail'));
    }
}
