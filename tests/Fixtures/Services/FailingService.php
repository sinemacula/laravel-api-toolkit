<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Exceptions\ServiceExecutionException;

/**
 * Fixture service that always fails.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, never>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FailingService extends Service
{
    /** @var bool Track whether onFailure() was called */
    public bool $onFailureCalled = false;

    /** @var \Throwable|null The exception passed to onFailure() */
    public ?\Throwable $onFailureException = null;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     */
    public function __construct(ServiceInput $input = new ArrayInput([]))
    {
        parent::__construct($input);
    }

    /**
     * React to a failed outcome after the transaction has rolled back.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    #[\Override]
    protected function onFailure(\Throwable $exception): void
    {
        $this->onFailureCalled    = true;
        $this->onFailureException = $exception;
    }

    /**
     * Handles the main execution of the service.
     *
     * @return never
     *
     * @throws \Tests\Fixtures\Exceptions\ServiceExecutionException
     */
    #[\Override]
    protected function handle(): never
    {
        throw new ServiceExecutionException('Service execution failed');
    }
}
