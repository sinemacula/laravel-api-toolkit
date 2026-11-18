<?php

declare(strict_types = 1);

namespace Benchmarks\Cache;

use Benchmarks\Support\BenchmarkApplication;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Cache\CacheManager;

/**
 * Benchmarks the per-operation flush a worker runs between requests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('microseconds')]
#[Bench\Revs(100)]
final class CacheFlushBench
{
    /** @var \SineMacula\ApiToolkit\Cache\CacheManager The manager under benchmark */
    private CacheManager $manager;

    /**
     * Resolve the manager and the services its flush resets.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->manager = BenchmarkApplication::create()->make(CacheManager::class);

        $this->benchFlush();
    }

    /**
     * Flush the per-operation state.
     *
     * @return void
     */
    public function benchFlush(): void
    {
        $this->manager->flush();
    }
}
