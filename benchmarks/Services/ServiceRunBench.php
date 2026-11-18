<?php

declare(strict_types = 1);

namespace Benchmarks\Services;

use Benchmarks\Support\BenchmarkApplication;
use Benchmarks\Support\ConcernedService;
use Benchmarks\Support\PassThroughConcern;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks a transactional service run bare and wrapped in three concerns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('microseconds')]
#[Bench\Revs(100)]
final class ServiceRunBench
{
    /** @var array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>> The concerns each run is wrapped in */
    private array $concerns;

    /**
     * Boot the application and warm the run path.
     *
     * @param  array{concerns: int}  $params
     * @return void
     */
    public function setUp(array $params): void
    {
        BenchmarkApplication::create();

        $this->concerns = array_fill(0, $params['concerns'], PassThroughConcern::class);

        $this->benchRun();
    }

    /**
     * Run the service.
     *
     * @return void
     */
    #[Bench\ParamProviders('provideConcerns')]
    public function benchRun(): void
    {
        (new ConcernedService($this->concerns))->run();
    }

    /**
     * Provide the number of concerns.
     *
     * @return \Generator<string, array{concerns: int}>
     */
    public function provideConcerns(): \Generator
    {
        yield 'no concerns' => ['concerns' => 0];
        yield '3 concerns' => ['concerns' => 3];
    }
}
