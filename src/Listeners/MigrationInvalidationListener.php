<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Cache\CacheManager;

/**
 * Invalidates the toolkit's cached schema metadata once migrations finish or a
 * stored schema is loaded.
 *
 * The schema has already changed, so a failure is logged, not thrown.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class MigrationInvalidationListener
{
    /**
     * Create a new migration invalidation listener instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\CacheManager  $cacheManager
     * @return void
     */
    public function __construct(

        /** The cache manager for invalidating toolkit metadata */
        private CacheManager $cacheManager,
    ) {}

    /**
     * Handle the event.
     *
     * @param  \Illuminate\Database\Events\MigrationsEnded|\Illuminate\Database\Events\SchemaLoaded  $event
     * @return void
     */
    public function handle(MigrationsEnded|SchemaLoaded $event): void
    {
        if ($event instanceof MigrationsEnded && ($event->options['pretend'] ?? false) === true) {
            return;
        }

        try {
            $this->cacheManager->invalidateMetadata();
        } catch (\Throwable $exception) {
            Log::warning(sprintf(
                'Toolkit metadata could not be invalidated after migrations: %s Run php artisan api-toolkit:invalidate-metadata once the cache store is available.',
                $exception->getMessage(),
            ), ['exception' => $exception]);
        }
    }
}
