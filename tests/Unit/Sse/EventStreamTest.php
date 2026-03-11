<?php

namespace Tests\Unit\Sse;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Sse\Emitter;
use SineMacula\ApiToolkit\Sse\EventStream;
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
class EventStreamTest extends TestCase
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

        static::assertInstanceOf(StreamedResponse::class, $response);
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

        static::assertSame('text/event-stream', $response->headers->get('Content-Type'));

        $cache_control = $response->headers->get('Cache-Control');

        static::assertStringContainsString('no-cache', $cache_control);
        static::assertStringContainsString('no-transform', $cache_control);
        static::assertSame('keep-alive', $response->headers->get('Connection'));
        static::assertSame('no', $response->headers->get('X-Accel-Buffering'));
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

        static::assertSame('abc123', $response->headers->get('X-Stream-Id'));
        static::assertSame('text/event-stream', $response->headers->get('Content-Type'));
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

        static::assertSame(202, $response->getStatusCode());
    }

    /**
     * Test that the callback is executed during stream content delivery.
     *
     * @return void
     */
    public function testStreamExecutesCallback(): void
    {
        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 2 ? 1 : 0;
        });

        $callback_ran = false;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callback_ran): void {
            $callback_ran = true;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertTrue($callback_ran);
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

        static::assertStringStartsWith(self::SSE_COMMENT, (string) $output);
    }

    /**
     * Test that a heartbeat comment is emitted after the interval elapses.
     *
     * @return void
     */
    public function testStreamEmitsHeartbeatAfterInterval(): void
    {
        $this->travelTo(now());

        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 4 ? 1 : 0;
        });

        $stream   = new EventStream(heartbeat_interval: 5);
        $response = $stream->toResponse(function (): void {
            $this->travel(6)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $comment_count = substr_count((string) $output, self::SSE_COMMENT);

        static::assertGreaterThanOrEqual(2, $comment_count);
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

        $callback_ran = false;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callback_ran): void {
            $callback_ran = true;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertFalse($callback_ran);
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
        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 3 ? 1 : 0;
        });

        $call_count = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$call_count): void {
            $call_count++;
            throw new \RuntimeException('Simulated stream failure');
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        static::assertStringContainsString("event: error\n\n", (string) $output);
        static::assertSame(1, $call_count);
    }

    /**
     * Test that the emitter is passed to callbacks that accept a parameter.
     *
     * @return void
     */
    public function testStreamPassesEmitterWhenCallbackAcceptsParameter(): void
    {
        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 2 ? 1 : 0;
        });

        $received_emitter = null;

        $stream   = new EventStream;
        $response = $stream->toResponse(function (Emitter $emitter) use (&$received_emitter): void {
            $received_emitter = $emitter;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertInstanceOf(Emitter::class, $received_emitter);
    }

    /**
     * Test that callbacks with no parameters are called without arguments.
     *
     * @return void
     */
    public function testStreamDoesNotPassEmitterWhenCallbackAcceptsNoParameters(): void
    {
        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 2 ? 1 : 0;
        });

        $args_received = null;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$args_received): void {
            $args_received = func_get_args();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertSame([], $args_received);
    }

    /**
     * Test that the default heartbeat interval is twenty seconds.
     *
     * @return void
     */
    public function testDefaultHeartbeatIntervalIsTwenty(): void
    {
        $this->travelTo(now());

        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 4 ? 1 : 0;
        });

        $iteration = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$iteration): void {
            $iteration++;

            if ($iteration === 1) {
                $this->travel(19)->seconds();
            }
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $comment_count = substr_count((string) $output, self::SSE_COMMENT);

        static::assertSame(1, $comment_count);
    }

    /**
     * Test that a custom heartbeat interval is respected.
     *
     * @return void
     */
    public function testCustomHeartbeatIntervalIsRespected(): void
    {
        $this->travelTo(now());

        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 4 ? 1 : 0;
        });

        $stream   = new EventStream(heartbeat_interval: 5);
        $response = $stream->toResponse(function (): void {
            $this->travel(5)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $comment_count = substr_count((string) $output, self::SSE_COMMENT);

        static::assertGreaterThanOrEqual(2, $comment_count);
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
        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 5 ? 1 : 0;
        });

        $call_count = 0;

        $stream = new class extends EventStream {
            /**
             * @param  \Throwable  $exception
             * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
             * @return bool
             */
            protected function handleStreamError(\Throwable $exception, Emitter $emitter): bool
            {
                return true;
            }
        };

        $response = $stream->toResponse(function () use (&$call_count): void {
            $call_count++;
            throw new \RuntimeException('Recoverable error');
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertGreaterThan(1, $call_count);
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
            protected function onStreamStart(Emitter $emitter): void
            {
                $emitter->emit('started', 'init');
            }
        };

        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        static::assertStringStartsWith("event: init\ndata: started\n\n", (string) $output);
        static::assertStringNotContainsString(self::SSE_COMMENT, (string) $output);
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
            protected function onStreamEnd(): void
            {
                $this->endCalled = true;
            }
        };

        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertTrue($stream->endCalled);
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

        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 3 ? 1 : 0;
        });

        $call_count = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$call_count): void {
            $call_count++;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertSame(1, $call_count);
    }
}
