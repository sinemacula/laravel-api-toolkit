<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that always succeeds.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, true>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SimpleService extends Service
{
    /** @var bool Track whether afterCommit() was called */
    public bool $afterCommitCalled = false;

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
     * React to a successful outcome after the transaction has committed.
     *
     * @param  mixed  $output
     * @return void
     */
    #[\Override]
    protected function afterCommit(mixed $output): void
    {
        $this->afterCommitCalled = true;
    }

    /**
     * Handles the main execution of the service.
     *
     * @return true
     */
    #[\Override]
    protected function handle(): mixed
    {
        return true;
    }
}
