<?php

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Services\Concerns\HasServiceLifecycle;

/**
 * Service using a consumer trait named like the toolkit's lifecycle trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class NamesakeTraitService extends Service
{
    use HasServiceLifecycle;

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
