<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Orchestra\Testbench\Foundation\Application as Testbench;
use SineMacula\ApiToolkit\ApiServiceProvider;

/**
 * The fresh application a command such as route:cache boots from the bootstrap
 * directory, with the package discovering resources against an unreachable
 * cache store.
 *
 * Its one route carries the resource map as a default, so the map the fresh
 * boot built can be read back from the compiled routes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FreshApplication
{
    /**
     * Create the fresh application.
     *
     * @param  string  $bootstrapPath
     * @param  array<int, string>  $paths
     * @return \Illuminate\Foundation\Application
     */
    public static function create(string $bootstrapPath, array $paths): Application
    {
        return Testbench::create(
            resolvingCallback: static function (Application $app) use ($bootstrapPath, $paths): void {

                $app->useBootstrapPath($bootstrapPath);

                $app->booting(static function (Application $app) use ($paths): void {

                    UnreachableCacheStore::install($app);

                    /** @var \Illuminate\Contracts\Config\Repository $config */
                    $config = $app->make('config');

                    $config->set('api-toolkit.resources.paths', $paths);
                    $config->set('api-toolkit.resources.resource_map', []);
                    $config->set('api-toolkit.resources.validate_schemas', false);
                });

                $app->booted(static function (Application $app): void {

                    /** @var \Illuminate\Contracts\Config\Repository $config */
                    $config = $app->make('config');

                    $app->make(Router::class)
                        ->redirect('resources', '/')
                        ->defaults('resource_map', $config->get('api-toolkit.resources.resource_map'));
                });
            },
            options: ['extra' => ['providers' => [ApiServiceProvider::class], 'dont-discover' => ['*']]],
        );
    }
}
