<?php

declare(strict_types = 1);

namespace Benchmarks\Repositories;

use Benchmarks\Support\BenchmarkApplication;
use Illuminate\Support\Facades\Config;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;

/**
 * Benchmarks flushing 10,000 buffered rows as chunked inserts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Revs(1)]
final class WritePoolFlushBench
{
    /** @var int The rows buffered */
    private const int ROWS = 10000;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool The pool under benchmark */
    private WritePool $pool;

    /**
     * Buffer the rows below the auto-flush limit.
     *
     * @return void
     */
    public function setUp(): void
    {
        $app = BenchmarkApplication::create();

        Config::set('api-toolkit.deferred_writes.pool_limit', self::ROWS + 1);

        $this->pool = $app->make(WritePool::class);

        foreach (range(1, self::ROWS) as $index) {
            $this->pool->add('benchmark', 'import_rows', ['reference' => 'row-' . $index, 'payload' => 'payload ' . $index]);
        }
    }

    /**
     * Flush the buffer.
     *
     * @return void
     */
    public function benchFlush(): void
    {
        $this->pool->flush();
    }
}
