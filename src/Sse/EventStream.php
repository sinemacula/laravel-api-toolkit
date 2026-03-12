<?php

namespace SineMacula\ApiToolkit\Sse;

use SineMacula\ApiToolkit\Enums\HttpStatus;
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
 */
class EventStream
{
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
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function toResponse(callable $callback, int $interval = 1, HttpStatus $status = HttpStatus::OK, array $headers = []): StreamedResponse
    {
        $headers = array_merge($headers, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
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
     * Reports the exception and emits a bare error event to the client.
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
        echo "event: error\n\n";
        flush();

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

            try {
                $acceptsEmitter ? $callback($emitter) : $callback();
            } catch (\Throwable $exception) {
                if (!$this->handleStreamError($exception, $emitter)) {
                    break;
                }

                continue;
            }

            $this->flushOutput();

            if ($heartbeatTimestamp->diffInSeconds(now()) >= $this->heartbeatInterval) {
                $emitter->comment();
                $heartbeatTimestamp = now();
            }

            // @phpstan-ignore-next-line if.alwaysFalse (connection state may change between the two checks per iteration)
            if (connection_aborted()) {
                break;
            }

            sleep($interval);
        }

        $this->onStreamEnd();
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
