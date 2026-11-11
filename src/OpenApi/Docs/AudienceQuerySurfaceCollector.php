<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;

/**
 * Collects the query surfaces each audience is allowed to be told about.
 *
 * A surface is kept only where the resource's schema survived into the
 * audience's document, so both are pruned by one decision.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class AudienceQuerySurfaceCollector
{
    /**
     * Create a new audience query surface collector.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents  $exporter
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration  $audiences
     */
    public function __construct(

        /** Assembles the document whose schemas decide what an audience reaches. */
        private ExportOpenApiComponents $exporter,

        /** The catalogue reporting every registered resource's query surface. */
        private MetadataCatalogue $catalogue,

        /** Resolves the configured audiences to collect for. */
        private AudienceConfiguration $audiences,
    ) {}

    /**
     * Collect the surfaces each configured audience reaches, keyed by audience.
     *
     * @return array<string, array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>>
     */
    public function collect(): array
    {
        $surfaces = $this->catalogue->getQuerySurfaces();
        $names    = $this->audiences->names();
        $names    = $names === [] ? [$this->audiences->defaultAudience()] : $names;

        $collected = [];

        foreach ($names as $audience) {
            $collected[$audience] = $this->reachedBy($audience, $surfaces);
        }

        return $collected;
    }

    /**
     * Reduce the surfaces to those whose schema the audience's document keeps.
     *
     * @param  string  $audience
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>
     */
    private function reachedBy(string $audience, array $surfaces): array
    {
        $schemas = $this->schemaNames($audience);

        return array_values(array_filter(
            $surfaces,
            static fn (QuerySurfaceDescriptor $surface): bool => in_array(
                SchemaComponentName::fromResource($surface->resource),
                $schemas,
                true,
            ),
        ));
    }

    /**
     * List the component schema names the named audience's document defines.
     *
     * @param  string  $audience
     * @return array<int, string>
     */
    private function schemaNames(string $audience): array
    {
        $components = $this->exporter->export($audience)->document['components'] ?? null;
        $schemas    = is_array($components) ? ($components['schemas'] ?? null) : null;

        return is_array($schemas) ? array_map('strval', array_keys($schemas)) : [];
    }
}
