<?php

declare(strict_types = 1);

namespace Tests\Unit\Sse;

use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Sse\Emitter;
use SineMacula\ApiToolkit\Sse\EventStream;
use SineMacula\Http\Enums\HttpStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * Tests for the SSE EventStream.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EventStream::class)]
final class EventStreamTest extends TestCase
{
    /** @var string The SSE comment wire format used for keep-alive signals. */
    private const string SSE_COMMENT = ":\n\n";

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);
        FunctionOverrides::set('ob_get_level', fn () => 0);
        FunctionOverrides::set('sleep', fn (int $_s) => 0);
        FunctionOverrides::set('connection_aborted', fn (): int => 1);
    }

    /**
     * Test that toResponse returns a StreamedResponse instance.
     *
     * @return void
     */
    public function testToResponseReturnsStreamedResponse(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null);

        self::assertInstanceOf(StreamedResponse::class, $response);
    }

    /**
     * Test that toResponse sets the required SSE headers.
     *
     * @return void
     */
    public function testToResponseSetsSseHeaders(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null);

        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));

        $cacheControl = $response->headers->get('Cache-Control');

        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringContainsString('no-transform', $cacheControl);
        self::assertSame('keep-alive', $response->headers->get('Connection'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    /**
     * Test that toResponse includes custom headers alongside SSE headers,
     * with SSE headers taking precedence over conflicting custom headers.
     *
     * @return void
     */
    public function testToResponseAcceptsCustomHeaders(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null, headers: [
            'X-Stream-Id'  => 'abc123',
            'Content-Type' => 'application/json',
        ]);

        self::assertSame('abc123', $response->headers->get('X-Stream-Id'));
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
    }

    /**
     * Test that toResponse accepts a custom HTTP status code.
     *
     * @return void
     */
    public function testToResponseAcceptsCustomStatus(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null, status: HttpStatus::ACCEPTED);

        self::assertSame(202, $response->getStatusCode());
    }

    /**
     * Test that the callback is executed during stream content delivery.
     *
     * @return void
     */
    public function testStreamExecutesCallback(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $callbackRan = false;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callbackRan): void {
            $callbackRan = true;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertTrue($callbackRan);
    }

    /**
     * Test that the stream emits an initial keep-alive comment.
     *
     * @return void
     */
    public function testStreamEmitsInitialKeepAliveComment(): void
    {
        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringStartsWith(self::SSE_COMMENT, (string) $output);
    }

    /**
     * Test that a heartbeat comment is emitted after the interval elapses.
     *
     * @return void
     */
    public function testStreamEmitsHeartbeatAfterInterval(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 4 ? 1 : 0;
        });

        $stream   = new EventStream(heartbeatInterval: 5);
        $response = $stream->toResponse(function (): void {
            $this->travel(6)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        self::assertGreaterThanOrEqual(2, $commentCount);
    }

    /**
     * Test that the loop breaks when connection_aborted returns truthy on
     * the first check, preventing the callback from executing.
     *
     * @return void
     */
    public function testStreamBreaksOnConnectionAborted(): void
    {
        FunctionOverrides::set('connection_aborted', fn (): int => 1);

        $callbackRan = false;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callbackRan): void {
            $callbackRan = true;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertFalse($callbackRan);
    }

    /**
     * Test that an error event is emitted and the loop breaks when the
     * callback throws an exception.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testStreamEmitsErrorEventWhenCallbackThrows(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $callCount = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;
            throw new \RuntimeException('Simulated stream failure');
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringContainsString("event: error\ndata: An error occurred\n\n", (string) $output);
        self::assertSame(1, $callCount);
    }

    /**
     * Test that the error event does not expose internal exception details
     * such as the exception message or class name.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testStreamErrorEventDoesNotExposeInternalDetails(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(function (): void {
            throw new \RuntimeException('Secret internal database error XYZ');
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringNotContainsString('Secret internal database error XYZ', (string) $output);
        self::assertStringNotContainsString(\RuntimeException::class, (string) $output);
        self::assertStringContainsString('data: An error occurred', (string) $output);
    }

    /**
     * Test that the emitter is passed to callbacks that accept a parameter.
     *
     * @return void
     */
    public function testStreamPassesEmitterWhenCallbackAcceptsParameter(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $receivedEmitter = null;

        $stream   = new EventStream;
        $response = $stream->toResponse(function (Emitter $emitter) use (&$receivedEmitter): void {
            $receivedEmitter = $emitter;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertInstanceOf(Emitter::class, $receivedEmitter);
    }

    /**
     * Test that callbacks with no parameters are called without arguments.
     *
     * @return void
     */
    public function testStreamDoesNotPassEmitterWhenCallbackAcceptsNoParameters(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $argsReceived = null;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$argsReceived): void {
            $argsReceived = func_get_args();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame([], $argsReceived);
    }

    /**
     * Test that the default heartbeat interval is twenty seconds.
     *
     * @return void
     */
    public function testDefaultHeartbeatIntervalIsTwenty(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 4 ? 1 : 0;
        });

        $iteration = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$iteration): void {
            $iteration++;

            if ($iteration !== 1) {
                return;
            }

            $this->travel(19)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        self::assertSame(1, $commentCount);
    }

    /**
     * Test that a custom heartbeat interval is respected.
     *
     * @return void
     */
    public function testCustomHeartbeatIntervalIsRespected(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 4 ? 1 : 0;
        });

        $stream   = new EventStream(heartbeatInterval: 5);
        $response = $stream->toResponse(function (): void {
            $this->travel(5)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        self::assertGreaterThanOrEqual(2, $commentCount);
    }

    /**
     * Test that handleStreamError is overridable by subclasses. When the
     * override returns true, the loop should continue and the callback
     * should run more than once.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testHandleStreamErrorIsOverridableBySubclass(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 5 ? 1 : 0;
        });

        $callCount = 0;

        $stream = new class extends EventStream {
            /**
             * @param  \Throwable  $exception
             * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
             * @return bool
             */
            #[\Override]
            protected function handleStreamError(\Throwable $exception, Emitter $emitter): bool
            {
                return true;
            }
        };

        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;
            throw new \RuntimeException('Recoverable error');
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertGreaterThan(1, $callCount);
    }

    /**
     * Test that onStreamStart is overridable by subclasses. When overridden,
     * the custom output should appear instead of the default keep-alive.
     *
     * @return void
     */
    public function testOnStreamStartIsOverridableBySubclass(): void
    {
        $stream = new class extends EventStream {
            /**
             * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
             * @return void
             */
            #[\Override]
            protected function onStreamStart(Emitter $emitter): void
            {
                $emitter->emit('started', 'init');
            }
        };

        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringStartsWith("event: init\ndata: started\n\n", (string) $output);
        self::assertStringNotContainsString(self::SSE_COMMENT, (string) $output);
    }

    /**
     * Test that onStreamEnd is called after the polling loop exits.
     *
     * @return void
     */
    public function testOnStreamEndIsCalledAfterLoopExits(): void
    {
        $stream = new class extends EventStream {
            /** @var bool */
            public bool $endCalled = false;

            /**
             * @return void
             */
            #[\Override]
            protected function onStreamEnd(): void
            {
                $this->endCalled = true;
            }
        };

        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertTrue($stream->endCalled);
    }

    /**
     * Test that the Cache-Control header is the exact comma-separated
     * directive list required for SSE responses. Symfony's header bag
     * appends the computed `private` directive.
     *
     * @return void
     */
    public function testCacheControlHeaderUsesCommaSeparatedDirectives(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null);

        self::assertSame('no-cache, no-transform, private', $response->headers->get('Cache-Control'));
    }

    /**
     * Test that the default heartbeat fires exactly at the twenty-second
     * boundary. Exactly twenty elapsed seconds must emit a heartbeat in
     * addition to the initial keep-alive comment.
     *
     * @return void
     */
    public function testDefaultHeartbeatFiresExactlyAtTwentySecondBoundary(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(function (): void {
            $this->travel(20)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        self::assertSame(2, $commentCount);
    }

    /**
     * Test that the loop sleeps exactly once per full iteration and that
     * the default polling interval of one second is forwarded to sleep.
     *
     * @return void
     */
    public function testSleepReceivesDefaultIntervalOncePerIteration(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $sleepArgs = [];

        FunctionOverrides::set('sleep', function (int $seconds) use (&$sleepArgs): int {
            $sleepArgs[] = $seconds;

            return 0;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame([1], $sleepArgs);
    }

    /**
     * Test that the loop terminates immediately after the first abort
     * check signals a disconnect and never polls the connection again.
     *
     * @return void
     */
    public function testStreamDoesNotRecheckConnectionAfterFirstAbort(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            if (++$abortCount > 1) {
                throw new \LogicException('Connection polled again after abort');
            }

            return 1;
        });

        $callbackRan = false;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callbackRan): void {
            $callbackRan = true;
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertSame(self::SSE_COMMENT, $output);
        self::assertFalse($callbackRan);
    }

    /**
     * Test that the second per-iteration abort check terminates the loop
     * entirely rather than skipping to a further iteration. The abort
     * sequence reports a disconnect on the second check only; the callback
     * must therefore run exactly once.
     *
     * @return void
     */
    public function testStreamDoesNotResumeAfterBreakOnSecondAbortCheck(): void
    {
        $aborts = [0, 1, 0, 0, 1];
        $index  = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$index, $aborts): int {
            return $aborts[$index++] ?? 1;
        });

        $callCount = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $callCount);
    }

    /**
     * Test that each iteration flushes the active output buffer via
     * ob_flush when a buffer level is active.
     *
     * @return void
     */
    public function testFlushOutputFlushesActiveOutputBuffer(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        FunctionOverrides::set('ob_get_level', fn (): int => 1);

        $obFlushCount = 0;

        FunctionOverrides::set('ob_flush', function () use (&$obFlushCount): void {
            $obFlushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $obFlushCount);
    }

    /**
     * Test that ob_flush is not called when no output buffer is active.
     *
     * @return void
     */
    public function testFlushOutputSkipsObFlushWhenNoBufferIsActive(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        FunctionOverrides::set('ob_get_level', fn (): int => 0);

        $obFlushCount = 0;

        FunctionOverrides::set('ob_flush', function () use (&$obFlushCount): void {
            $obFlushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(0, $obFlushCount);
    }

    /**
     * Test that the system output buffer is flushed once per iteration in
     * addition to the flush performed by the initial keep-alive comment.
     *
     * @return void
     */
    public function testFlushOutputFlushesSystemBufferEachIteration(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $flushCount = 0;

        FunctionOverrides::set('flush', function () use (&$flushCount): void {
            $flushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(2, $flushCount);
    }

    /**
     * Test that handleStreamError is callable from a subclass, reports the
     * exception through the application exception handler, emits the
     * generic error event, and signals the loop to stop.
     *
     * @return void
     */
    public function testHandleStreamErrorReportsExceptionAndIsCallableFromSubclass(): void
    {
        $exception = new \RuntimeException('Simulated stream failure');

        /** @var \Illuminate\Contracts\Debug\ExceptionHandler&\Mockery\MockInterface $handler */
        $handler = \Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($exception);

        assert($this->app !== null);

        $this->app->instance(ExceptionHandler::class, $handler);

        $stream = new class extends EventStream {
            /**
             * @param  \Throwable  $exception
             * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
             * @return bool
             */
            public function exposeHandleStreamError(\Throwable $exception, Emitter $emitter): bool
            {
                return $this->handleStreamError($exception, $emitter);
            }
        };

        ob_start();
        $result = $stream->exposeHandleStreamError($exception, new Emitter);
        $output = ob_get_clean();

        self::assertFalse($result);
        self::assertStringContainsString("event: error\ndata: An error occurred\n\n", (string) $output);
    }

    /**
     * Test that onStreamStart is callable from a subclass and emits the
     * initial keep-alive comment by default.
     *
     * @return void
     */
    public function testOnStreamStartIsCallableFromSubclass(): void
    {
        $stream = new class extends EventStream {
            /**
             * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
             * @return void
             */
            public function exposeOnStreamStart(Emitter $emitter): void
            {
                $this->onStreamStart($emitter);
            }
        };

        ob_start();
        $stream->exposeOnStreamStart(new Emitter);
        $output = ob_get_clean();

        self::assertSame(self::SSE_COMMENT, $output);
    }

    /**
     * Test that onStreamEnd is callable from a subclass and produces no
     * output by default.
     *
     * @return void
     */
    public function testOnStreamEndIsCallableFromSubclass(): void
    {
        $stream = new class extends EventStream {
            /**
             * @return void
             */
            public function exposeOnStreamEnd(): void
            {
                $this->onStreamEnd();
            }
        };

        ob_start();
        $stream->exposeOnStreamEnd();
        $output = ob_get_clean();

        self::assertSame('', $output);
    }

    /**
     * Test that the loop breaks on the second abort check within an
     * iteration. The first and second checks pass, allowing the callback
     * to run, then the third check (second per-iteration check) triggers
     * the break.
     *
     * @return void
     */
    public function testStreamBreaksOnSecondAbortCheck(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $callCount = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $callCount);
    }
}
