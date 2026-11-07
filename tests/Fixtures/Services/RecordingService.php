<?php

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Services\Service;
use Throwable;

/**
 * Service with a parameterless handler that logs each step it runs.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\Illuminate\Support\Collection<string, mixed>>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class RecordingService extends Service
{
    /**
     * Prepare the service for execution.
     *
     * @return void
     */
    public function prepare(): void
    {
        $this->log()->add('prepare');
    }

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * @return void
     */
    public function success(): void
    {
        $this->log()->add('success');
    }

    /**
     * Method is triggered if the handle method failed.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $this->log()->add('failed:' . $exception->getMessage());
    }

    /**
     * Return the key the service locks on.
     *
     * @return string
     */
    public function lockKey(): string
    {
        return $this->generateLockKey();
    }

    /**
     * Handle the service.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    protected function handle(): bool
    {
        $this->log()->add('handle');

        if ($this->getLockId() !== '') {
            $this->log()->add(Cache::lock($this->lockKey(), 1)->get() ? 'unlocked' : 'locked');
        }

        $exception = $this->payload->get('exception');

        if ($exception instanceof Throwable) {
            throw $exception;
        }

        return (bool) $this->payload->get('result', true);
    }

    /**
     * Return the unique id to be used in the generation of the lock key.
     *
     * @return string
     */
    protected function getLockId(): string
    {
        return (string) $this->payload->get('lock_id', '');
    }

    /**
     * Return the log the service writes to.
     *
     * @return \Tests\Fixtures\Services\ServiceLog
     */
    private function log(): ServiceLog
    {
        return $this->payload->get('log');
    }
}
