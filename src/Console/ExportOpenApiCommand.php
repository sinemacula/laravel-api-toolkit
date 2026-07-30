<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;

/**
 * Artisan command to export the toolkit's OpenAPI 3.1 document.
 *
 * Walks the application route table, the registered resource map, the operator
 * grammar, and the error catalogue to assemble a schema-valid OpenAPI 3.1
 * document for a target audience, then serializes it to pretty-printed JSON and
 * writes it through the DocumentWriter output port. Without options the default
 * audience is exported to the resolved output path; --audience exports a named
 * audience; --all exports every configured audience, each to its own path
 * derived by injecting the audience name before the output extension. The
 * command is opt-in -- invoked explicitly, mirroring ValidateSchemasCommand --
 * and never runs as part of request handling.
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
    protected $signature = <<<'EOD'
        api-toolkit:export-openapi
                {--output= : The path to write the OpenAPI document to}
                {--audience= : The audience to export; defaults to the configured default audience}
                {--all : Export every configured audience, each to its own output path}
        EOD;

    /** @var string The console command description. */
    protected $description = 'Export the toolkit metadata as an OpenAPI 3.1 document';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents  $exporter
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter  $writer
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration  $audiences
     * @return int
     */
    public function handle(ExportOpenApiComponents $exporter, DocumentWriter $writer, AudienceConfiguration $audiences): int
    {
        if (!$this->hasDatabaseConnection()) {
            $this->warn(self::NO_DATABASE_NOTICE);
        }

        $targets = $this->resolveAudiences($audiences);

        if ($targets === null) {
            return self::FAILURE;
        }

        $base    = $this->resolveOutputPath();
        $perFile = (bool) $this->option('all');

        foreach ($targets as $audience) {

            $result = $exporter->export($audience);

            if ($result->resourceCount === 0) {
                $this->components->warn('No resources registered in the resource map; nothing was exported.');

                return self::SUCCESS;
            }

            $this->writeDocument($writer, $result, $perFile ? $this->audienceOutputPath($base, $audience) : $base, $audience);
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the audiences to export, or null when a named audience is
     * unknown.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration  $audiences
     * @return list<string>|null
     */
    private function resolveAudiences(AudienceConfiguration $audiences): ?array
    {
        if ($this->option('all')) {
            $names = $audiences->names();

            return $names === [] ? [$audiences->defaultAudience()] : $names;
        }

        $requested = $this->option('audience');

        if (!is_string($requested) || $requested === '') {
            return [$audiences->defaultAudience()];
        }

        return $this->resolveNamedAudience($audiences, $requested);
    }

    /**
     * Resolve a single named audience, printing an error and returning null
     * when it is not configured.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration  $audiences
     * @param  string  $requested
     * @return list<string>|null
     */
    private function resolveNamedAudience(AudienceConfiguration $audiences, string $requested): ?array
    {
        if ($audiences->has($requested)) {
            return [$requested];
        }

        $this->components->error(sprintf(
            'Unknown audience [%s]. Configured audiences: %s.',
            $requested,
            implode(', ', $audiences->names()) ?: '(none)',
        ));

        return null;
    }

    /**
     * Serialize and write the exported document, reporting the emission.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter  $writer
     * @param  \SineMacula\ApiToolkit\OpenApi\ExportResult  $result
     * @param  string  $path
     * @param  string  $audience
     * @return void
     *
     * @throws \JsonException
     */
    private function writeDocument(DocumentWriter $writer, ExportResult $result, string $path, string $audience): void
    {
        $json = json_encode($result->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $writer->write($path, $json);

        $this->components->info(sprintf(
            'Exported %d resource schema(s), %d query parameter(s), and %d error response(s) for the %s audience to %s.',
            $result->resourceCount,
            $result->parameterCount,
            $result->responseCount,
            $audience,
            $path,
        ));
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
     * Resolve the base output path from the command option, falling back to the
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

    /**
     * Derive a per-audience output path by injecting the audience name before
     * the base path's extension, e.g. openapi.json becomes openapi.public.json.
     *
     * @param  string  $base
     * @param  string  $audience
     * @return string
     */
    private function audienceOutputPath(string $base, string $audience): string
    {
        $extension = pathinfo($base, PATHINFO_EXTENSION);

        if ($extension === '') {
            return $base . '.' . $audience;
        }

        return substr($base, 0, -(strlen($extension) + 1)) . '.' . $audience . '.' . $extension;
    }
}
