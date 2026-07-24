<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;

/**
 * Artisan command to export the toolkit's OpenAPI 3.1 components document.
 *
 * Walks the registered resource map, the operator grammar, and the error
 * catalogue to assemble a schema-valid OpenAPI 3.1 components document, then
 * serializes it to pretty-printed JSON and writes it through the DocumentWriter
 * output port. The command is opt-in -- invoked explicitly, mirroring
 * ValidateSchemasCommand -- and never runs as part of request handling.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExportOpenApiCommand extends Command
{
    /** @var string The notice printed when the export runs without a database. */
    private const string NO_DATABASE_NOTICE = 'No database connection - field types and nullability are inferred'
        . ' from declared casts only; run against a database for the most accurate output.';

    /** @var string The console command signature. */
    protected $signature = 'api-toolkit:export-openapi {--output= : The path to write the OpenAPI document to}';

    /** @var string The console command description. */
    protected $description = 'Export the toolkit metadata as an OpenAPI 3.1 components document';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents  $exporter
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter  $writer
     * @return int
     */
    public function handle(ExportOpenApiComponents $exporter, DocumentWriter $writer): int
    {
        if (!$this->hasDatabaseConnection()) {
            $this->warn(self::NO_DATABASE_NOTICE);
        }

        $result = $exporter->export();

        if ($result->resourceCount === 0) {
            $this->components->warn('No resources registered in the resource map; nothing was exported.');

            return self::SUCCESS;
        }

        $path = $this->resolveOutputPath();
        $json = json_encode($result->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $writer->write($path, $json);

        $this->components->info(sprintf(
            'Exported %d resource schema(s), %d query parameter(s), and %d error response(s) to %s.',
            $result->resourceCount,
            $result->parameterCount,
            $result->responseCount,
            $path,
        ));

        return self::SUCCESS;
    }

    /**
     * Determine whether a live database connection is available.
     *
     * Column type and nullability inference is the only part of the export that
     * needs a connection; when none can be established the export still runs
     * against declared casts alone.
     *
     * @return bool
     */
    private function hasDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the output path from the command option, falling back to the
     * configured default.
     *
     * @return string
     */
    private function resolveOutputPath(): string
    {
        $option = $this->option('output');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $configured = Config::get('api-toolkit.openapi.output');

        return is_string($configured) && $configured !== '' ? $configured : base_path('openapi.json');
    }
}
