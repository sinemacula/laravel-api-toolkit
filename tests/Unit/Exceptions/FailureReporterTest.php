<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use Tests\Concerns\SwapsExceptionHandler;
use Tests\TestCase;

/**
 * Tests for the FailureReporter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FailureReporter::class)]
final class FailureReporterTest extends TestCase
{
    use SwapsExceptionHandler;

    /**
     * Test that the exact exception instance is reported through the exception
     * handler without writing the fallback log line.
     *
     * @return void
     */
    public function testReportsTheExceptionThroughTheHandler(): void
    {
        Exceptions::fake();
        Log::shouldReceive('error')->never();

        $original = new \RuntimeException('boom');

        FailureReporter::report($original, 'Something failed');

        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e === $original);
        Exceptions::assertReportedCount(1);
    }

    /**
     * Test that a failure of reporting falls back to an error log line that
     * carries the original exception, not the reporting failure.
     *
     * @return void
     */
    public function testFallsBackToLoggingTheOriginalWhenReportingFails(): void
    {
        $handler  = $this->useThrowingExceptionHandler();
        $original = new \RuntimeException('boom');

        Log::shouldReceive('error')
            ->once()
            ->with('Something failed', \Mockery::on(fn (array $context): bool => $context === ['exception' => $original]));

        FailureReporter::report($original, 'Something failed');

        self::assertSame([$original], $handler->reported);
    }

    /**
     * Test that a failure of the fallback log write is swallowed and the
     * handler is not asked to report again.
     *
     * @return void
     */
    public function testSwallowsAFailureOfTheFallbackLogWrite(): void
    {
        $handler  = $this->useThrowingExceptionHandler();
        $original = new \RuntimeException('boom');

        Log::shouldReceive('error')
            ->once()
            ->andThrow(new \RuntimeException('log down'));

        FailureReporter::report($original, 'Something failed');

        self::assertSame([$original], $handler->reported);
    }
}
