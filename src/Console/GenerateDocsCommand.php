<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Docs\AudienceQuerySurfaceCollector;
use SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory;
use SineMacula\ApiToolkit\OpenApi\Docs\EnumReferenceDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Docs\ErrorCatalogueDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Docs\ReferencedEnumCollector;

/**
 * Artisan command to generate the reference documentation sections.
 *
 * The query surface reference discloses as much as a resource's schema, so it
 * is written per audience; the other sections are shared.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GenerateDocsCommand extends Command
{
    /** @var string The generated error catalogue filename */
    private const string ERROR_FILE = '40-error-catalogue.md';

    /** @var string The generated enum reference filename */
    private const string ENUM_FILE = '50-enum-reference.md';

    /** @var string The generated query surface reference filename, written per audience */
    private const string SURFACE_FILE = '60-query-surface-reference.md';

    /** @var string The console command signature */
    protected $signature = 'api-toolkit:docs:generate';

    /** @var string The console command description */
    protected $description = 'Generate the auto-generated OpenAPI reference documentation sections';

    /**
     * Execute the console command.
     *
     * Every section is generated before any is written, so a failed export
     * leaves the docs directory untouched.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\ErrorCatalogueDocGenerator  $errors
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\EnumReferenceDocGenerator  $enums
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator  $surface
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\ReferencedEnumCollector  $collector
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\AudienceQuerySurfaceCollector  $surfaces
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter  $writer
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory  $docs
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
        DocsDirectory $docs,
    ): int {
        $directory = rtrim($docs->root(), '/');

        $files = [
            $directory . '/' . self::ERROR_FILE => $errors->generate($catalogue->getErrorCatalogue()),
            $directory . '/' . self::ENUM_FILE  => $enums->generate($collector->collect()),
            ...$this->surfaceReferences($docs, $catalogue, $surface, $surfaces),
        ];

        foreach ($files as $path => $contents) {

            $writer->write($path, $contents);

            $this->components->info(sprintf('Wrote %s.', $path));
        }

        return self::SUCCESS;
    }

    /**
     * Generate one query surface reference per audience, keyed by its path.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory  $docs
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator  $surface
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\AudienceQuerySurfaceCollector  $surfaces
     * @return array<string, string>
     */
    private function surfaceReferences(DocsDirectory $docs, MetadataCatalogue $catalogue, QuerySurfaceDocGenerator $surface, AudienceQuerySurfaceCollector $surfaces): array
    {
        $limits     = $catalogue->getQueryLimits();
        $bounds     = $catalogue->getSearchBounds();
        $references = [];

        foreach ($surfaces->collect() as $audience => $reached) {
            $references[$docs->audienceRoot($audience) . '/' . self::SURFACE_FILE] = $surface->generate($reached, $limits, $bounds);
        }

        return $references;
    }
}
