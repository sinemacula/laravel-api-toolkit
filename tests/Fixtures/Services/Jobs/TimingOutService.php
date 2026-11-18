<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use Illuminate\Queue\Attributes\Timeout;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service whose run outlives its one-second timeout.
 *
 * The worker that times it out kills its own process, so the failure hooks
 * append to a file the test reads afterwards.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Timeout(1)]
final class TimingOutService extends Service
{
    /** @var string The file each failure hook appends a line to */
    public static string $log = '';

    /**
     * Sleep past the timeout.
     *
     * @return null
     */
    #[\Override]
    protected function handle(): null
    {
        sleep(10);

        return null;
    }

    /**
     * Log the failed attempt.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFailure(\Throwable $exception): void
    {
        file_put_contents(self::$log, 'onFailure ' . $exception::class . PHP_EOL, FILE_APPEND);
    }

    /**
     * Log the exception the queue gave up on.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFinalFailure(\Throwable $exception): void
    {
        file_put_contents(self::$log, 'onFinalFailure ' . $exception::class . PHP_EOL, FILE_APPEND);
    }
}
