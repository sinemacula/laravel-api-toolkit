<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\InjectableService;

/**
 * Service that does not define a handler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class HandlerlessService extends InjectableService
{
    /** @var bool Whether the prepare hook ran */
    public bool $prepared = false;

    /**
     * Prepare the service for execution.
     *
     * @return void
     */
    public function prepare(): void
    {
        $this->prepared = true;
    }
}
