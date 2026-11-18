<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture lockable service whose lockId() names a record that no longer exists,
 * so every rebuilt run fails before it starts.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MissingLockSubjectService extends Service
{
    /** @var array<int, \Throwable> The exceptions onFinalFailure() received */
    public static array $finalFailures = [];

    /** @var bool Whether this service acquires a cache lock */
    protected bool $lockable = true;

    /**
     * Reset the recorded failures between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$finalFailures = [];
    }

    /**
     * Complete the run.
     *
     * @return null
     */
    #[\Override]
    protected function handle(): null
    {
        return null;
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

    /**
     * Fail to resolve the record the lock is named after.
     *
     * @return string
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    #[\Override]
    protected function lockId(): string
    {
        throw new ModelNotFoundException('The locked record no longer exists.');
    }
}
