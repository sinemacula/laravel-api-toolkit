<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Sse;

use Carbon\CarbonInterface;
use SineMacula\Http\Enums\CacheDirective;
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\HttpStatus;
use SineMacula\Http\Enums\MediaType;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE transport lifecycle manager.
 *
 * Owns response construction, the polling loop, heartbeat emission,
 * connection-abort detection, and error handling for Server-Sent Event
 * streams. Designed for subclass extension via protected hooks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @inheritable
 */
class EventStream
{
    /** Poll outcome: callback ran cleanly; proceed with the iteration. */
    private const string POLL_CONTINUE = 'continue';

    /** Poll outcome: the error handler asked to retry the next iteration. */
    private const string POLL_RETRY = 'retry';

    /** Poll outcome: the error handler asked to terminate the loop. */
    private const string POLL_BREAK = 'break';

    /**
     * Create a new event stream instance.
     *
     * @param  int  $heartbeatInterval
     */
    public function __construct(

        /** The heartbeat interval in seconds for keep-alive comments. */
        private readonly int $heartbeatInterval = 20,

    ) {}

    /**
     * Build an SSE streamed response.
     *
     * Constructs a StreamedResponse with the required SSE headers and a
     * streaming closure that runs the polling loop. Callback arity is
     * detected via reflection to determine whether the emitter is passed.
     *
     * @param  callable(): void|callable(\SineMacula\ApiToolkit\Sse\Emitter): void  $callback
     * @param  int  $interval
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function toResponse(
        callable $callback,
        int $interval = 1,
        HttpStatus $status = HttpStatus::OK,
        array $headers = []
    ): StreamedResponse {
        $headers = array_merge($headers, [
            HttpHeader::CONTENT_TYPE->getName()      => MediaType::TEXT_EVENT_STREAM->getMimeType(),
            HttpHeader::CACHE_CONTROL->getName()     => CacheDirective::NO_CACHE->value . ', ' . CacheDirective::NO_TRANSFORM->value,
            HttpHeader::CONNECTION->getName()        => 'keep-alive',
            HttpHeader::X_ACCEL_BUFFERING->getName() => 'no',
        ]);

        $acceptsEmitter = (new \ReflectionFunction(\Closure::fromCallable($callback)))->getNumberOfParameters() >= 1;
        $emitter        = new Emitter;

        return new StreamedResponse(function () use ($callback, $interval, $emitter, $acceptsEmitter): void {
            $this->runEventStream($callback, $interval, $emitter, $acceptsEmitter);
        }, $status->getCode(), $headers);
    }

    /**
     * Handle a stream error.
     *
     * Reports the exception and emits an error event to the client.
     * Returns false to break the polling loop, or true to continue.
     * Subclasses may override this to implement recovery strategies.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \Throwable  $exception
     * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
     * @return bool
     */
    protected function handleStreamError(\Throwable $exception, Emitter $emitter): bool
    {
        report($exception);
        $emitter->emit('An error occurred', 'error');

        return false;
    }

    /**
     * Called once before the polling loop begins.
     *
     * The default implementation emits an initial keep-alive comment.
     * Subclasses may override to perform custom initialisation.
     *
     * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
     * @return void
     */
    protected function onStreamStart(Emitter $emitter): void
    {
        $emitter->comment();
    }

    /**
     * Called after the polling loop exits.
     *
     * The default implementation is empty. Subclasses may override to
     * perform cleanup such as releasing resources or logging.
     *
     * @return void
     */
    protected function onStreamEnd(): void {}

    /**
     * Execute the SSE polling loop.
     *
     * Emits an initial keep-alive comment, then polls the callback on each
     * iteration, sending a heartbeat comment when the configured interval
     * elapses. Exits when the client disconnects or an unrecoverable error
     * occurs.
     *
     * @param  callable(): void|callable(\SineMacula\ApiToolkit\Sse\Emitter): void  $callback
     * @param  int  $interval
     * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
     * @param  bool  $acceptsEmitter
     * @return void
     */
    private function runEventStream(callable $callback, int $interval, Emitter $emitter, bool $acceptsEmitter): void
    {
        $this->onStreamStart($emitter);

        $heartbeatTimestamp = now();

        while (true) {

            if (connection_aborted()) {
                break;
            }

            $outcome = $this->pollCallback($callback, $emitter, $acceptsEmitter);

            if ($outcome === self::POLL_BREAK) {
                break;
            }

            if ($outcome === self::POLL_RETRY) {
                continue;
            }

            $this->flushOutput();
            $this->emitHeartbeatIfDue($emitter, $heartbeatTimestamp);

            // @phpstan-ignore-next-line if.alwaysFalse (connection state may change between the two checks per iteration)
            if (connection_aborted()) {
                break;
            }

            sleep($interval);
        }

        $this->onStreamEnd();
    }

    /**
     * Invoke the streaming callback and classify the loop outcome.
     *
     * Returns one of the POLL_* signals: the callback ran cleanly
     * (continue the iteration), the error handler asked to retry, or it
     * asked to terminate the loop.
     *
     * @param  callable(): void|callable(\SineMacula\ApiToolkit\Sse\Emitter): void  $callback
     * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
     * @param  bool  $acceptsEmitter
     * @return self::POLL_*
     */
    private function pollCallback(callable $callback, Emitter $emitter, bool $acceptsEmitter): string
    {
        try {
            $acceptsEmitter ? $callback($emitter) : $callback();
        } catch (\Throwable $exception) {
            return $this->handleStreamError($exception, $emitter)
                ? self::POLL_RETRY
                : self::POLL_BREAK;
        }

        return self::POLL_CONTINUE;
    }

    /**
     * Emit a heartbeat comment when the configured interval has elapsed.
     *
     * Resets the heartbeat timestamp (passed by reference) whenever a
     * heartbeat is emitted so the next interval is measured afresh.
     *
     * @param  \SineMacula\ApiToolkit\Sse\Emitter  $emitter
     * @param  \Carbon\CarbonInterface  $heartbeatTimestamp
     * @return void
     */
    private function emitHeartbeatIfDue(Emitter $emitter, CarbonInterface &$heartbeatTimestamp): void
    {
        if ($heartbeatTimestamp->diffInSeconds(now()) < $this->heartbeatInterval) {
            return;
        }

        $emitter->comment();

        $heartbeatTimestamp = now();
    }

    /**
     * Flush any active output buffers and the system output buffer.
     *
     * @return void
     */
    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
