<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\TestCase;

/**
 * Integration test for a throwing afterCommit hook over a real transaction.
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

        // The committed write survives and the hook failure is captured
        self::assertTrue($result->succeeded());
        self::assertSame('written', $result->output());

        $errors = $result->sideEffectErrors();

        self::assertCount(1, $errors);
        self::assertInstanceOf(\RuntimeException::class, $errors[0]);
        self::assertSame('after commit failed', $errors[0]->getMessage());

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $errors[0]);

        $this->assertDatabaseHas('users', ['email' => 'after-commit@service.test']);
    }
}
