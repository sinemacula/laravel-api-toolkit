<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Fixtures\Services\Jobs\ContextReadingService;
use Tests\Fixtures\Services\TransactionRecordingService;
use Tests\TestCase;

/**
 * Integration test for afterCommit() over a real top-level transaction.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
final class ServiceAfterCommitTest extends TestCase
{
    use CommitsRealTransactions;

    /**
     * Set up each test with a fake exception handler for the reported error.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();
    }

    /**
     * Test that afterCommit() runs after the commit, and its throw is captured
     * while the write survives.
     *
     * @return void
     */
    public function testAfterCommitThrowIsCapturedAndWritePersists(): void
    {
        $error   = new \RuntimeException('after commit failed');
        $service = $this->insertingService();

        $service->onCommit = static fn (): never => throw $error;

        $result = $service->run();

        self::assertTrue($result->succeeded());
        self::assertSame('written', $result->output());
        self::assertSame([0], $service->commitLevels);
        self::assertSame([$error], $result->sideEffectErrors());

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $error);

        $this->assertDatabaseHas('users', ['email' => 'after-commit@service.test']);
    }

    /**
     * Test that a failed queue push from afterCommit() is a reported side
     * effect of a committed run.
     *
     * @return void
     */
    public function testFailedPushFromAfterCommitIsASideEffectError(): void
    {
        Config::set('queue.connections.unreachable', ['driver' => 'database', 'table' => 'missing_jobs', 'queue' => 'default', 'retry_after' => 90]);
        Config::set('queue.default', 'unreachable');

        $service = $this->insertingService();

        $service->onCommit = static function (): void {
            ContextReadingService::make(new ArrayInput([]))->dispatch();
        };

        $result = $service->run();

        self::assertTrue($result->succeeded());
        self::assertCount(1, $result->sideEffectErrors());
        self::assertInstanceOf(QueryException::class, $result->sideEffectErrors()[0]);

        Exceptions::assertReported(static fn (QueryException $e): bool => $e === $result->sideEffectErrors()[0]);

        $this->assertDatabaseHas('users', ['email' => 'after-commit@service.test']);
    }

    /**
     * Build a service that inserts a users row.
     *
     * @return \Tests\Fixtures\Services\TransactionRecordingService
     */
    private function insertingService(): TransactionRecordingService
    {
        return new TransactionRecordingService(static function (): string {
            DB::table('users')->insert([
                'name'  => 'after_commit',
                'email' => 'after-commit@service.test',
            ]);

            return 'written';
        });
    }
}
