<?php

declare(strict_types = 1);

namespace Benchmarks\Providers;

use Benchmarks\Support\BenchmarkApplication;
use Benchmarks\Support\WideUserResource;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\ApiServiceProvider;

/**
 * Benchmarks booting the toolkit's provider over a compiled map of 50 resource
 * bindings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\AfterMethods('tearDown')]
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('microseconds')]
#[Bench\Revs(10)]
final class ProviderBootBench
{
    /** @var \Illuminate\Foundation\Application The application the provider boots in */
    private Application $app;

    /** @var string The bootstrap directory holding the compiled map */
    private string $bootstrapPath;

    /**
     * Write the compiled map to a bootstrap directory of the run's own.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->app           = BenchmarkApplication::create();
        $this->bootstrapPath = sys_get_temp_dir() . '/api-toolkit-bench-' . getmypid();

        $map = [];

        foreach (range(1, 50) as $index) {
            $map['App\Models\Model' . $index] = WideUserResource::class;
        }

        mkdir($this->bootstrapPath . '/cache', 0o755, true);
        file_put_contents($this->bootstrapPath . '/cache/api-toolkit-resources.php', '<?php return ' . var_export($map, true) . ';');

        $this->app->useBootstrapPath($this->bootstrapPath);
    }

    /**
     * Remove the bootstrap directory.
     *
     * @return void
     */
    public function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->bootstrapPath);
    }

    /**
     * Boot a fresh provider.
     *
     * @return void
     */
    public function benchBoot(): void
    {
        (new ApiServiceProvider($this->app))->boot();
    }
}
