<?php

declare(strict_types = 1);

namespace Benchmarks\OpenApi;

use Benchmarks\Support\BenchmarkApplication;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use Tests\Fixtures\OpenApi\GeneratedUserRoutes;

/**
 * Benchmarks exporting the OpenAPI document of an application of 50, 200 and
 * 800 routes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Revs(1)]
final class OpenApiExportBench
{
    /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents The export under benchmark */
    private ExportOpenApiComponents $exporter;

    /**
     * Register the routes and warm the catalogue reads with a first export.
     *
     * @param  array{routes: int}  $params
     * @return void
     */
    public function setUp(array $params): void
    {
        $app = BenchmarkApplication::create();

        Config::set('api-toolkit.resources.resource_map', GeneratedUserRoutes::RESOURCE_MAP);

        GeneratedUserRoutes::register($app->make(Router::class), 0, $params['routes']);

        $this->exporter = $app->make(ExportOpenApiComponents::class);
        $this->exporter->export();
    }

    /**
     * Export the document.
     *
     * @return void
     */
    #[Bench\ParamProviders('provideRoutes')]
    public function benchExport(): void
    {
        $this->exporter->export();
    }

    /**
     * Provide the route counts.
     *
     * @return \Generator<string, array{routes: int}>
     */
    public function provideRoutes(): \Generator
    {
        foreach ([50, 200, 800] as $routes) {
            yield $routes . ' routes' => ['routes' => $routes];
        }
    }
}
