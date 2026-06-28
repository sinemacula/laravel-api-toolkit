<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Jobs;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that runs ContextCapturingConcern to expose the context.
 *
 * Used by ServiceJob tests to verify that the execution context (including
 * source) is correctly forwarded by the queue bridge.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, string>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ConcernCapturingService extends Service
{
    /**
     * Return the concern list for this fixture service.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    #[\Override]
    protected function concerns(): array
    {
        return [ContextCapturingConcern::class];
    }

    /**
     * Return a fixed output value.
     *
     * @return string
     */
    #[\Override]
    protected function handle(): string
    {
        return 'ran';
    }
}
