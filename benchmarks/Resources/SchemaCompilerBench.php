<?php

declare(strict_types = 1);

namespace Benchmarks\Resources;

use Benchmarks\Support\BenchmarkApplication;
use Benchmarks\Support\WideUserResource;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Benchmarks compiling a 30-field resource schema, cold and memoised.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('microseconds')]
#[Bench\Revs(100)]
final class SchemaCompilerBench
{
    /**
     * Boot the application.
     *
     * @return void
     */
    public function setUp(): void
    {
        BenchmarkApplication::create();

        SchemaCompiler::compile(WideUserResource::class);
    }

    /**
     * Compile the schema from scratch.
     *
     * @return void
     */
    public function benchCold(): void
    {
        SchemaCompiler::clearCache();
        SchemaCompiler::compile(WideUserResource::class);
    }

    /**
     * Read the memoised schema.
     *
     * @return void
     */
    public function benchWarm(): void
    {
        SchemaCompiler::compile(WideUserResource::class);
    }
}
