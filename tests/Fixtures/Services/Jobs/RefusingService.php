<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Exceptions\PreconditionFailedException;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;

/**
 * Fixture service that refuses its run with the configured exception.
 *
 * authorize() throws an AuthorizationException, validate() a
 * ValidationException and handle() a PreconditionFailedException. The queue
 * bridge builds the service itself, so the refusal and the hook counts are held
 * in static properties.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RefusingService extends Service
{
    /** @var \Throwable|null The refusal the next run throws */
    public static ?\Throwable $refusal = null;

    /** @var int The number of times authorize() has run */
    public static int $attempts = 0;

    /** @var int The number of times onFailure() has run */
    public static int $failures = 0;

    /** @var array<int, \Throwable> The exceptions onFinalFailure() received */
    public static array $finalFailures = [];

    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context onFinalFailure() last read */
    public static ?ServiceContext $finalContext = null;

    /**
     * Reset the refusal and the counts between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$refusal       = null;
        self::$attempts      = 0;
        self::$failures      = 0;
        self::$finalFailures = [];
        self::$finalContext  = null;
    }

    /**
     * Count the attempt and throw an authorization refusal.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[\Override]
    protected function authorize(): void
    {
        self::$attempts++;

        if (self::$refusal instanceof AuthorizationException) {
            throw self::$refusal;
        }
    }

    /**
     * Throw a validation refusal.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    #[\Override]
    protected function validate(): void
    {
        if (self::$refusal instanceof ValidationException) {
            throw self::$refusal;
        }
    }

    /**
     * Throw a precondition refusal, or complete the run.
     *
     * @return null
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PreconditionFailedException
     */
    #[\Override]
    protected function handle(): null
    {
        if (self::$refusal instanceof PreconditionFailedException) {
            throw self::$refusal;
        }

        return null;
    }

    /**
     * Count the failed attempt.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFailure(\Throwable $exception): void
    {
        self::$failures++;
    }

    /**
     * Record the exception the queue gave up on and the context it ran in.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFinalFailure(\Throwable $exception): void
    {
        self::$finalFailures[] = $exception;
        self::$finalContext    = $this->context();
    }
}
