<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use Illuminate\Queue\Attributes\Tries;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Exceptions\ServiceExecutionException;

/**
 * Fixture service that declares a single attempt and always fails.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, never>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Tries(1)]
final class SingleAttemptService extends Service
{
    /** @var int The number of times the service has run */
    public static int $runs = 0;

    /** @var array<int, \Throwable> The exceptions onFinalFailure() received */
    public static array $finalFailures = [];

    /**
     * Reset the counts between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$runs          = 0;
        self::$finalFailures = [];
    }

    /**
     * Count the run, then fail it.
     *
     * @return never
     *
     * @throws \Tests\Fixtures\Exceptions\ServiceExecutionException
     */
    #[\Override]
    protected function handle(): never
    {
        self::$runs++;

        throw new ServiceExecutionException('Single attempt failed.');
    }

    /**
     * Record the exception the queue gave up on.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFinalFailure(\Throwable $exception): void
    {
        self::$finalFailures[] = $exception;
    }
}
