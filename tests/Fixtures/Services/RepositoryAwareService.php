<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Service that uses repository accessors and does not define a prepare hook.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class RepositoryAwareService extends Service
{
    use HasRepositories;

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
