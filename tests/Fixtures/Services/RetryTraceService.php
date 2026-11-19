<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Services\Concerns\OuterRecordingConcern;
use Tests\Fixtures\Services\Concerns\RecordingConcern;

/**
 * Fixture lockable service with two transaction attempts whose first attempt
 * throws the given error.
 *
 * prepare() and handle() add to the recording concern's trace, handle() records
 * whether its lock was free, and each lock acquisition is counted.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, string>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RetryTraceService extends Service
{
    /** @var int The number of times the lock was acquired */
    public int $locks = 0;

    /** @var array<int, bool> Whether another owner could take the lock, per attempt */
    public array $lockFree = [];

    /** @var int Transaction attempts; a top-level run retries a concurrency error */
    protected int $transactionAttempts = 2;

    /** @var bool Whether this service acquires a cache lock */
    protected bool $lockable = true;

    /** @var \Throwable The error the first attempt throws */
    private readonly \Throwable $firstAttemptError;

    /**
     * Constructor.
     *
     * @param  \Throwable  $firstAttemptError
     */
    public function __construct(\Throwable $firstAttemptError)
    {
        parent::__construct(new ArrayInput([]));

        $this->firstAttemptError = $firstAttemptError;
    }

    /**
     * Count the acquisition and acquire the lock.
     *
     * @return \Illuminate\Contracts\Cache\Lock
     */
    #[\Override]
    public function lock(): Lock
    {
        $this->locks++;

        return parent::lock();
    }

    /**
     * Return the recording concern.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    #[\Override]
    protected function concerns(): array
    {
        return [OuterRecordingConcern::class];
    }

    /**
     * Trace the prepare step.
     *
     * @return void
     */
    #[\Override]
    protected function prepare(): void
    {
        RecordingConcern::push('prepare');
    }

    /**
     * Trace the handle step and record the lock, failing the first attempt.
     *
     * @return string
     *
     * @throws \Throwable
     */
    #[\Override]
    protected function handle(): string
    {
        RecordingConcern::push('handle');

        $this->lockFree[] = Cache::lock($this->getLockKey(), 1)->get(static fn (): bool => true) === true;

        if (count($this->lockFree) === 1) {
            throw $this->firstAttemptError;
        }

        return 'done';
    }

    /**
     * Return the unique lock identity for this invocation.
     *
     * @return string
     */
    #[\Override]
    protected function lockId(): string
    {
        return 'retry-trace';
    }
}
