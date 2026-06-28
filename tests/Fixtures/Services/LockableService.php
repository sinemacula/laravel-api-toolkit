<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service with locking enabled.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, true>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LockableService extends Service
{
    /** @var bool Whether this service acquires a cache lock */
    protected bool $lockable = true;

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
     * Handles the main execution of the service.
     *
     * @return true
     */
    #[\Override]
    protected function handle(): mixed
    {
        return true;
    }

    /**
     * Return the unique lock identity for this invocation.
     *
     * @return string
     */
    #[\Override]
    protected function lockId(): string
    {
        return 'lockable-test';
    }
}
