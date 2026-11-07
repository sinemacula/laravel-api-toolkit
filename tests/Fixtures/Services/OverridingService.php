<?php

namespace Tests\Fixtures\Services;

use Override;
use SineMacula\ApiToolkit\Services\Service;
use Throwable;

/**
 * Service that marks every hook it overrides with the override attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class OverridingService extends Service
{
    /** @var list<string> The hooks that ran */
    public array $ran = [];

    /**
     * Prepare the service for execution.
     *
     * @return void
     */
    #[Override]
    public function prepare(): void
    {
        parent::prepare();

        $this->ran[] = 'prepare';
    }

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * @return void
     */
    #[Override]
    public function success(): void
    {
        $this->ran[] = 'success';
    }

    /**
     * Method is triggered if the handle method failed.
     *
     * @param  Throwable  $exception
     * @return void
     */
    #[Override]
    public function failed(Throwable $exception): void
    {
        $this->ran[] = 'failed:' . $exception->getMessage();
    }

    /**
     * Handle the service.
     *
     * @return bool
     */
    #[Override]
    protected function handle(): bool
    {
        $this->ran[] = 'handle';

        return true;
    }

    /**
     * Return the unique id to be used in the generation of the lock key.
     *
     * @return string
     */
    #[Override]
    protected function getLockId(): string
    {
        return 'overriding';
    }
}
