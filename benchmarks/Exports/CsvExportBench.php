<?php

declare(strict_types = 1);

namespace Benchmarks\Exports;

use Benchmarks\Support\BenchmarkApplication;
use PhpBench\Attributes as Bench;
use SineMacula\Exporter\ExporterServiceProvider;
use SineMacula\Exporter\Facades\Exporter;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ExportableUserResource;
use Tests\Fixtures\Support\UserGraphSeeder;

/**
 * Benchmarks streaming 10,000 rows as CSV, so the peak memory of a streamed
 * export is reported alongside its time.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Revs(1)]
final class CsvExportBench
{
    /**
     * Seed the rows.
     *
     * @return void
     */
    public function setUp(): void
    {
        BenchmarkApplication::create(ExporterServiceProvider::class);

        UserGraphSeeder::seed(10000);

        // Report the export's peak, not the seeding's
        memory_reset_peak_usage();
    }

    /**
     * Stream the export to a file.
     *
     * @return void
     */
    public function benchStream(): void
    {
        $stream = fopen('php://temp/maxmemory:0', 'w+b');

        assert($stream !== false);

        Exporter::query(User::query()->with('organization'), ExportableUserResource::class)->format('csv')->toStream($stream);

        fclose($stream);
    }
}
