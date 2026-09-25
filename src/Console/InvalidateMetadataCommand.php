<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Exceptions\MetadataInvalidationException;

/**
 * Artisan command to invalidate the toolkit's cached schema metadata.
 *
 * Metadata is cached in the shared store, mostly forever, and a process only
 * forgets the keys it touched itself. Run this on every deploy once the new
 * release is live, so no process serves metadata that old code wrote after the
 * migrations ran.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InvalidateMetadataCommand extends Command
{
    /** @var string The console command signature. */
    protected $signature = 'api-toolkit:invalidate-metadata';

    /** @var string The console command description. */
    protected $description = 'Invalidate the cached schema metadata in every process sharing the cache store';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\Cache\CacheManager  $cacheManager
     * @return int
     */
    public function handle(CacheManager $cacheManager): int
    {
        try {
            $cacheManager->invalidateMetadata();
        } catch (MetadataInvalidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Toolkit metadata invalidated.');

        return self::SUCCESS;
    }
}
