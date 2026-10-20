<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;

/**
 * Artisan command to compile the attribute-discovered resource map.
 *
 * Scans the configured resource paths and writes the model to resource map to a
 * PHP file under bootstrap/cache, which discovery then serves on every boot
 * instead of scanning. Run by php artisan optimize; rebuild it after adding,
 * moving, or re-binding a resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CacheResourcesCommand extends Command
{
    /** @var string The console command signature. */
    protected $signature = 'api-toolkit:resources:cache';

    /** @var string The console command description. */
    protected $description = 'Discover the attribute-bound API resources and compile the model to resource map';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery  $discovery
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return int
     */
    public function handle(ResourceDiscovery $discovery, Filesystem $files): int
    {
        $path = $discovery->cachePath();

        $files->ensureDirectoryExists(dirname($path));
        $files->replace($path, '<?php return ' . var_export($discovery->scan(), true) . ';');

        $this->components->info('API resources cached successfully.');

        return self::SUCCESS;
    }
}
