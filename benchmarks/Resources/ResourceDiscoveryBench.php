<?php

declare(strict_types = 1);

namespace Benchmarks\Resources;

use Benchmarks\Support\BenchmarkApplication;
use Illuminate\Support\Facades\Config;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;

/**
 * Benchmarks scanning the fixture resource directories for model bindings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Revs(10)]
final class ResourceDiscoveryBench
{
    /** @var \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery The discovery under benchmark */
    private ResourceDiscovery $discovery;

    /**
     * Point discovery at the fixture resources.
     *
     * @return void
     */
    public function setUp(): void
    {
        $app      = BenchmarkApplication::create();
        $fixtures = dirname(__DIR__, 2) . '/tests/Fixtures';

        Config::set('api-toolkit.resources.paths', [$fixtures . '/Resources', $fixtures . '/Discovery/Primary']);

        $this->discovery = $app->make(ResourceDiscovery::class);
    }

    /**
     * Scan the directories.
     *
     * @return void
     */
    public function benchScan(): void
    {
        $this->discovery->scan();
    }
}
