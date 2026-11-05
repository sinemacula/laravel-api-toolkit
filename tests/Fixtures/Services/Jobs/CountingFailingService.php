<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Exceptions\ServiceExecutionException;

/**
 * Fixture service that always fails and counts how often it ran.
 *
 * The queue bridge builds the service itself, so the run count is held in a
 * static property for ServiceJob tests to assert how often a worker attempted
 * the job.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, never>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CountingFailingService extends Service
{
    /** @var string The message of the exception every run throws */
    public const string MESSAGE = 'Queued service failed.';

    /** @var int The number of times the service has run */
    public static int $runs = 0;

    /**
     * Reset the run count between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$runs = 0;
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

        throw new ServiceExecutionException(self::MESSAGE);
    }
}
