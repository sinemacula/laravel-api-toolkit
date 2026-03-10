<?php

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use SineMacula\ApiToolkit\Cache\CacheManager;

/**
 * Artisan command to flush all toolkit caches.
 *
 * Useful during deployment scripts, after migrations, or for debugging
 * stale cached metadata.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FlushCachesCommand extends Command
{
    /** @var string The console command signature. */
    protected $signature = 'api-toolkit:flush-caches';

    /** @var string The console command description. */
    protected $description = 'Flush all API toolkit caches';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\Cache\CacheManager  $cacheManager
     * @return int
     */
    public function handle(CacheManager $cacheManager): int
    {
        $cacheManager->flush();

        $this->components->info('All API toolkit caches have been flushed.');

        return self::SUCCESS;
    }
}
