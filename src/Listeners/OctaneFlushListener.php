<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;

/**
 * Resets the toolkit's in-process state after each Octane request, task and
 * tick, so in-process memos do not grow without bound.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class OctaneFlushListener
{
    /**
     * Create a new octane flush listener instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\CacheManager  $cacheManager
     * @param  \SineMacula\ApiToolkit\Runtime\RuntimeContext  $runtime
     * @return void
     */
    public function __construct(

        /** The cache manager for flushing toolkit caches */
        private CacheManager $cacheManager,

        /** The runtime context for detecting the serving environment */
        private RuntimeContext $runtime,
    ) {}

    /**
     * Handle the event.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  object  $event
     * @return void
     */
    public function handle(object $event): void
    {
        if (!$this->runtime->isServingUnderOctane()) {
            return;
        }

        try {
            $this->cacheManager->flush();
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
            // Never crash the worker
            FailureReporter::report($exception, 'Octane cache flush failed');
        }
    }
}
