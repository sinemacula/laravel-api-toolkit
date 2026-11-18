<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\Foundation\Application as Testbench;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Fixtures\Resources\ExportableUserResource;
use Tests\Fixtures\Resources\GuardedUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Boots a Testbench application serving the toolkit over an in-memory SQLite
 * database that holds the benchmark tables, with the benchmarked resources
 * catalogued so their field selections parse.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BenchmarkApplication
{
    /**
     * Create the application with the toolkit and the given providers.
     *
     * @param  class-string<\Illuminate\Support\ServiceProvider>  ...$providers
     * @return \Illuminate\Foundation\Application
     */
    public static function create(string ...$providers): Application
    {
        $app = Testbench::create(options: [
            'extra' => [
                'dont-discover' => ['*'],
                'providers'     => [ApiServiceProvider::class, ...array_values($providers)],
            ],
        ]);

        Config::set('database.default', 'benchmark');
        Config::set('database.connections.benchmark', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('cache.default', 'array');
        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            ExportableUserResource::class,
            GuardedUserResource::class,
            OrganizationResource::class,
            PostResource::class,
            TagResource::class,
            UserResource::class,
            WideUserResource::class,
        ]);

        BenchmarkDatabase::migrate();

        return $app;
    }
}
