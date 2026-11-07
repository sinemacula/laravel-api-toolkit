<?php

namespace SineMacula\ApiToolkit\Services;

use Exception;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInterface;
use SineMacula\ApiToolkit\Services\Traits\HasServiceLifecycle;
use SineMacula\ApiToolkit\Traits\Lockable;

/**
 * Base API service.
 *
 * @template TPayload of array<array-key, mixed>|\Illuminate\Support\Collection<array-key, mixed>|\stdClass = \Illuminate\Support\Collection<array-key, mixed>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class Service implements ServiceInterface
{
    /** @use \SineMacula\ApiToolkit\Services\Traits\HasServiceLifecycle<TPayload> */
    use HasServiceLifecycle;
    use Lockable;

    /**
     * Prepare the service for execution.
     *
     * @return void
     *
     * @throws Exception
     */
    public function prepare(): void
    {
    }

    /**
     * Run the service.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    public function run(): bool
    {
        return $this->runLifecycle(fn () => $this->prepare(), fn () => $this->handle());
    }

    /**
     * Handles the main execution of the service.
     *
     * @return bool
     */
    abstract protected function handle(): bool;
}
