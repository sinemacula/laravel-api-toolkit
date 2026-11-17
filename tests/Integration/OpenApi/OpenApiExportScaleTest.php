<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\OpenApi\GeneratedUserRoutes;
use Tests\TestCase;

/**
 * Pins how the OpenAPI export grows with the number of routes.
 *
 * Each test runs in a process of its own, so neither the measurements nor the
 * memory limit are shared with earlier tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiComponents::class)]
#[CoversClass(OpenApiAssembler::class)]
#[RunTestsInSeparateProcesses]
final class OpenApiExportScaleTest extends TestCase
{
    use RecordsStatements;

    /** @var int The routes of the small application */
    private const int SMALL = 50;

    /** @var int The routes of the large application */
    private const int LARGE = 500;

    /** @var int The routes registered so far */
    private int $routes = 0;

    /**
     * Set up each test with the resources the routes reference and a second
     * audience.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.resources.resource_map', GeneratedUserRoutes::RESOURCE_MAP);
        Config::set('api-toolkit.openapi.audiences', ['public' => [], 'partner' => []]);
    }

    /**
     * Test that a cold export reads the catalogue the same number of times at
     * either size, and a repeated export or another audience reads it no more.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\MetadataInvalidationException
     */
    public function testCatalogueReadsDoNotGrowWithRoutesExportsOrAudiences(): void
    {
        $this->addRoutes(self::SMALL);

        $small = $this->recordStatements(fn (): ExportResult => $this->export());

        self::assertNotEmpty($small);
        self::assertStatementCount(0, $this->recordStatements(fn (): ExportResult => $this->export()));
        self::assertStatementCount(0, $this->recordStatements(fn (): ExportResult => $this->export('partner')));

        $this->cacheManager()->invalidateMetadata();
        $this->addRoutes(self::LARGE - self::SMALL);

        self::assertStatementCount(count($small), $this->recordStatements(fn (): ExportResult => $this->export()));
        self::assertStatementCount(0, $this->recordStatements(fn (): ExportResult => $this->export('partner')));
    }

    /**
     * Test that the time per operation stays flat and the memory grows no
     * faster than the routes.
     *
     * @return void
     */
    public function testTimeAndMemoryGrowLinearlyWithRoutes(): void
    {
        $this->addRoutes(self::SMALL);
        $this->export();

        $small = $this->measure();

        $this->addRoutes(self::LARGE - self::SMALL);

        $large = $this->measure();

        self::assertLessThan(3 * $small['time'], $large['time']);
        self::assertLessThan(2 * self::LARGE / self::SMALL * $small['memory'], $large['memory']);
    }

    /**
     * Register the given number of further routes.
     *
     * @param  int  $count
     * @return void
     */
    private function addRoutes(int $count): void
    {
        assert($this->app !== null);

        GeneratedUserRoutes::register($this->app->make(Router::class), $this->routes, $count);

        $this->routes += $count;
    }

    /**
     * Export the routes registered so far three times, returning the fastest
     * time per operation and the peak memory the last export added.
     *
     * @return array{time: float, memory: int}
     */
    private function measure(): array
    {
        $time   = INF;
        $memory = 0;

        for ($run = 0; $run < 3; $run++) {

            memory_reset_peak_usage();

            $baseline = memory_get_usage();
            $start    = hrtime(true);
            $result   = $this->export();
            $time     = min($time, (hrtime(true) - $start) / $this->routes);
            $memory   = memory_get_peak_usage() - $baseline;

            self::assertSame($this->routes, $result->operationCount);
            self::assertIsArray($result->document['paths']);
            self::assertCount(intdiv($this->routes, 2), $result->document['paths']);
        }

        return ['time' => $time, 'memory' => $memory];
    }

    /**
     * Export the document for the given audience.
     *
     * @param  string|null  $audience
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(?string $audience = null): ExportResult
    {
        assert($this->app !== null);

        return $this->app->make(ExportOpenApiComponents::class)->export($audience);
    }

    /**
     * Resolve the cache manager.
     *
     * @return \SineMacula\ApiToolkit\Cache\CacheManager
     */
    private function cacheManager(): CacheManager
    {
        assert($this->app !== null);

        return $this->app->make(CacheManager::class);
    }
}
