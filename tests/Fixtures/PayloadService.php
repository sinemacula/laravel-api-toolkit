<?php

namespace Tests\Fixtures;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Service that exposes the payload it was given.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class PayloadService extends Service
{
    /**
     * Return the payload the service holds.
     *
     * @return mixed
     */
    public function payload(): mixed
    {
        return $this->payload;
    }

    /**
     * Handle the service.
     *
     * @return bool
     */
    protected function handle(): bool
    {
        return true;
    }
}
