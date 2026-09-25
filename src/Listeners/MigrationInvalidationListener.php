<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Listeners;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Cache\CacheManager;

/**
 * Invalidates the toolkit's cached schema metadata once migrations finish.
 *
 * A migration run is exactly when cached column listings, definitions, and
 * index catalogues go stale. The framework only fires the event after at least
 * one migration ran or rolled back, so a deploy with nothing to migrate keeps
 * its warm metadata. A pretended run changes nothing and is ignored.
 *
 * The migrations have already run when the event fires, so a failure to
 * invalidate is logged rather than thrown, and the migrate command still
 * succeeds. A rollback can drop the cache store's own table, taking the
 * metadata it held with it; any other failure leaves the warning naming the
 * command to run by hand.
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

        /** The cache manager for invalidating toolkit metadata. */
        private CacheManager $cacheManager,
    ) {}

    /**
     * Handle the event.
     *
     * @param  \Illuminate\Database\Events\MigrationsEnded  $event
     * @return void
     */
    public function handle(MigrationsEnded $event): void
    {
        if (($event->options['pretend'] ?? false) === true) {
            return;
        }

        try {
            $this->cacheManager->invalidateMetadata();
        } catch (\Exception $exception) {
            Log::warning(sprintf(
                'Toolkit metadata could not be invalidated after migrations: %s Run php artisan api-toolkit:invalidate-metadata once the cache store is available.',
                $exception->getMessage(),
            ), ['exception' => $exception]);
        }
    }
}
