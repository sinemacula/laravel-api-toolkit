<?php

namespace SineMacula\ApiToolkit\Services\Contracts;

/**
 * Service interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ServiceInterface
{
    /**
     * Run the service.
     *
     * @return bool
     */
    public function run(): bool;

    /**
     * Get the service status.
     *
     * @return bool|null
     */
    public function getStatus(): ?bool;
}
