<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture lockable service with configurable transaction settings that records
 * whether validate() and handle() ran.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, null>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TransactionAttemptsService extends Service
{
    /** @var bool Whether validate() ran */
    public bool $validated = false;

    /** @var bool Whether handle() ran */
    public bool $handled = false;

    /** @var bool Whether this service acquires a cache lock */
    protected bool $lockable = true;

    /**
     * Constructor.
     *
     * @param  int  $attempts
     * @param  bool  $transactional
     */
    public function __construct(int $attempts, bool $transactional = true)
    {
        parent::__construct(new ArrayInput([]));

        $this->transactionAttempts = $attempts;
        $this->transactional       = $transactional;
    }

    /**
     * Record that validation ran.
     *
     * @return void
     */
    #[\Override]
    protected function validate(): void
    {
        $this->validated = true;
    }

    /**
     * Record that the core action ran.
     *
     * @return null
     */
    #[\Override]
    protected function handle(): mixed
    {
        $this->handled = true;

        return null;
    }

    /**
     * Return the unique lock identity for this invocation.
     *
     * @return string
     */
    #[\Override]
    protected function lockId(): string
    {
        return 'transaction-attempts';
    }
}
