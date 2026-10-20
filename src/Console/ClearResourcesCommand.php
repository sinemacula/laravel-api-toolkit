<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;

/**
 * Artisan command to remove the compiled resource map.
 *
 * Once the compiled map is gone, discovery scans the configured resource paths
 * on every boot again. Run by php artisan optimize:clear.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ClearResourcesCommand extends Command
{
    /** @var string The console command signature. */
    protected $signature = 'api-toolkit:resources:clear';

    /** @var string The console command description. */
    protected $description = 'Remove the compiled API resource map';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery  $discovery
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return int
     */
    public function handle(ResourceDiscovery $discovery, Filesystem $files): int
    {
        $files->delete($discovery->cachePath());

        $this->components->info('Cached API resources cleared successfully.');

        return self::SUCCESS;
    }
}
