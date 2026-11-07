<?php

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Services\InjectableService;
use Throwable;

/**
 * Injectable service whose handler declares its dependencies as parameters and
 * that does not define a prepare hook.
 *
 * @extends \SineMacula\ApiToolkit\Services\InjectableService<\Illuminate\Support\Collection<string, mixed>>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class InjectedService extends InjectableService
{
    /** @var \Tests\Fixtures\Services\ServiceProbe|null The probe the handler received */
    public ?ServiceProbe $probe = null;

    /** @var \Throwable|null The exception passed to the failed hook */
    public ?Throwable $failure = null;

    /**
     * Method is triggered if the handle method failed.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $this->failure = $exception;
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
     * @param  \Tests\Fixtures\Services\ServiceLog  $log
     * @param  \Tests\Fixtures\Services\ServiceProbe  $probe
     * @param  string  $label
     * @return bool
     *
     * @throws \Throwable
     */
    protected function handle(ServiceLog $log, ServiceProbe $probe, string $label = 'default'): bool
    {
        $this->probe = $probe;

        $log->add('handle:' . $label);

        if ($this->getLockId() !== '') {
            $log->add(Cache::lock($this->lockKey(), 1)->get() ? 'unlocked' : 'locked');
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
}
