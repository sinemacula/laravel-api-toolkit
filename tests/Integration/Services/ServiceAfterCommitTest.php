<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\TestCase;

/**
 * Integration test for a throwing afterCommit hook over a real transaction.
 *
 * Proves that when the core write commits and the afterCommit hook then throws,
 * the committed row survives and the failure is captured as a side-effect error
 * on a still-successful result rather than rolling back or escaping the runner.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
final class ServiceAfterCommitTest extends TestCase
{
    /**
     * Set up each test with a silent log channel for the captured error.
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
     * Test that a throwing afterCommit captures while the write survives.
     *
     * @return void
     */
    public function testAfterCommitThrowIsCapturedAndWritePersists(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Insert a row inside the committed transaction.
             *
             * @return string
             */
            #[\Override]
            protected function handle(): mixed
            {
                DB::table('users')->insert([
                    'name'  => 'after_commit',
                    'email' => 'after-commit@service.test',
                ]);

                return 'written';
            }

            /**
             * Throw after the transaction has already committed.
             *
             * The output is required by the hook signature but not used here.
             *
             * @SuppressWarnings("php:S1172")
             *
             * @param  mixed  $output
             * @return never
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function afterCommit(mixed $output): never
            {
                throw new \RuntimeException('after commit failed');
            }
        };

        $result = $service->run();

        // The run still succeeds: the committed write is intact and the
        // afterCommit failure is captured rather than rethrown or rolled back.
        self::assertTrue($result->succeeded());
        self::assertSame('written', $result->output());

        $errors = $result->sideEffectErrors();

        self::assertCount(1, $errors);
        self::assertInstanceOf(\RuntimeException::class, $errors[0]);
        self::assertSame('after commit failed', $errors[0]->getMessage());

        $this->assertDatabaseHas('users', ['email' => 'after-commit@service.test']);
    }
}
