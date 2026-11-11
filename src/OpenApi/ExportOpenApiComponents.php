<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;

/**
 * Exports the toolkit's OpenAPI 3.1 document and summarises its contents.
 *
 * Counts are read from the assembled document, so pruned schemas are not
 * counted.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportOpenApiComponents
{
    /**
     * Create a new export use case.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\OpenApiAssembler  $assembler
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     */
    public function __construct(

        /** The assembler that builds the OpenAPI components document. */
        private OpenApiAssembler $assembler,

        /** The catalogue naming the resources whose schemas are counted. */
        private MetadataCatalogue $catalogue,
    ) {}

    /**
     * Assemble the document for the given audience and summarise the emission.
     *
     * @param  string|null  $audience
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    public function export(?string $audience = null): ExportResult
    {
        $document = $this->assembler->assemble($audience);

        return new ExportResult(
            document      : $document,
            operationCount: $this->countOperations($document),
            resourceCount : $this->countResourceSchemas($document),
            parameterCount: $this->countComponents($document, 'parameters'),
            responseCount : $this->countComponents($document, 'responses'),
        );
    }

    /**
     * Count the operations across every path item of the assembled document.
     *
     * @param  array<string, mixed>  $document
     * @return int
     */
    private function countOperations(array $document): int
    {
        $paths = $document['paths'] ?? null;

        if (!is_array($paths)) {
            return 0;
        }

        $count = 0;

        foreach ($paths as $operations) {
            $count += is_array($operations) ? count($operations) : 0;
        }

        return $count;
    }

    /**
     * Count the resource component schemas, excluding envelopes and enums.
     *
     * @param  array<string, mixed>  $document
     * @return int
     */
    private function countResourceSchemas(array $document): int
    {
        $names = array_map(SchemaComponentName::fromResource(...), $this->catalogue->getResourceMap());

        return count(array_intersect_key($this->componentSection($document, 'schemas'), array_flip($names)));
    }

    /**
     * Count the entries in a named components section of the document.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $section
     * @return int
     */
    private function countComponents(array $document, string $section): int
    {
        return count($this->componentSection($document, $section));
    }

    /**
     * Resolve a named components section of the document, empty when missing.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $section
     * @return array<array-key, mixed>
     */
    private function componentSection(array $document, string $section): array
    {
        $components = $document['components'] ?? [];

        if (!is_array($components) || !is_array($components[$section] ?? null)) {
            return [];
        }

        return $components[$section];
    }
}
