<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Docs\AudienceQuerySurfaceCollector;
use SineMacula\ApiToolkit\OpenApi\Docs\DocManualAssembler;
use SineMacula\ApiToolkit\OpenApi\Docs\EnumReferenceDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Docs\ErrorCatalogueDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Docs\ReferencedEnumCollector;

/**
 * Artisan command to generate the auto-generated reference documentation.
 *
 * Writes the error catalogue, the enum reference, and the query surface
 * reference as Markdown section files into the configured docs directory, where
 * the manual assembler concatenates them into the OpenAPI info.description
 * alongside the fixed templates. The numeric filename prefixes order the
 * generated sections after the shipped templates. Every file is regenerated
 * unconditionally and its content is derived purely from the metadata with no
 * timestamps, so a second run yields byte-identical output. The command is
 * opt-in, mirroring the other exporter commands, and never runs as part of
 * request handling.
 *
 * The query surface reference tables what each resource may be asked, which is
 * the same disclosure that resource's schema is, so it is written once per
 * audience into that audience's own section directory and reaches only the
 * document whose schemas the resource survived into. The error catalogue and
 * the enum reference describe the API as a whole and stay shared.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GenerateDocsCommand extends Command
{
    /** @var string The generated error catalogue filename. */
    private const string ERROR_FILE = '40-error-catalogue.md';

    /** @var string The generated enum reference filename. */
    private const string ENUM_FILE = '50-enum-reference.md';

    /** @var string The generated query surface reference filename, written per audience. */
    private const string SURFACE_FILE = '60-query-surface-reference.md';

    /** @var string The default docs directory, relative to the resources. */
    private const string DEFAULT_DIRECTORY = 'api-docs';

    /** @var string The console command signature. */
    protected $signature = 'api-toolkit:docs:generate';

    /** @var string The console command description. */
    protected $description = 'Generate the auto-generated OpenAPI reference documentation sections';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\ErrorCatalogueDocGenerator  $errors
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\EnumReferenceDocGenerator  $enums
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator  $surface
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\ReferencedEnumCollector  $collector
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\AudienceQuerySurfaceCollector  $surfaces
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter  $writer
     * @return int
     */
    public function handle(
        MetadataCatalogue $catalogue,
        ErrorCatalogueDocGenerator $errors,
        EnumReferenceDocGenerator $enums,
        QuerySurfaceDocGenerator $surface,
        ReferencedEnumCollector $collector,
        AudienceQuerySurfaceCollector $surfaces,
        DocumentWriter $writer,
    ): int {
        $directory = $this->docsPath();

        $this->writeFile($writer, $directory, self::ERROR_FILE, $errors->generate($catalogue->getErrorCatalogue()));
        $this->writeFile($writer, $directory, self::ENUM_FILE, $enums->generate($collector->collect()));

        $this->writeSurfaceReferences($writer, $directory, $catalogue, $surface, $surfaces);

        return self::SUCCESS;
    }

    /**
     * Write one query surface reference per audience, each holding only the
     * resources that audience's document reaches.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter  $writer
     * @param  string  $directory
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator  $surface
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\AudienceQuerySurfaceCollector  $surfaces
     * @return void
     */
    private function writeSurfaceReferences(
        DocumentWriter $writer,
        string $directory,
        MetadataCatalogue $catalogue,
        QuerySurfaceDocGenerator $surface,
        AudienceQuerySurfaceCollector $surfaces,
    ): void {
        $limits = $catalogue->getQueryLimits();
        $bounds = $catalogue->getSearchBounds();

        foreach ($surfaces->collect() as $audience => $reached) {
            $this->writeFile(
                $writer,
                $this->audiencePath($directory, $audience),
                self::SURFACE_FILE,
                $surface->generate($reached, $limits, $bounds),
            );
        }
    }

    /**
     * Resolve the section directory belonging to a single audience.
     *
     * @param  string  $directory
     * @param  string  $audience
     * @return string
     */
    private function audiencePath(string $directory, string $audience): string
    {
        return rtrim($directory, '/') . '/' . DocManualAssembler::AUDIENCE_DIRECTORY . '/' . $audience;
    }

    /**
     * Write one generated section file and report the path written.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter  $writer
     * @param  string  $directory
     * @param  string  $filename
     * @param  string  $contents
     * @return void
     */
    private function writeFile(DocumentWriter $writer, string $directory, string $filename, string $contents): void
    {
        $path = rtrim($directory, '/') . '/' . $filename;

        $writer->write($path, $contents);

        $this->components->info(sprintf('Wrote %s.', $path));
    }

    /**
     * Resolve the docs directory, falling back to the application resources
     * directory so the generated files land where the assembler reads them.
     *
     * @return string
     */
    private function docsPath(): string
    {
        $configured = Config::get('api-toolkit.openapi.docs_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : resource_path(self::DEFAULT_DIRECTORY);
    }
}
