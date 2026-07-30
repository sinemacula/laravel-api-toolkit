<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;

/**
 * Application use case that exports the toolkit's OpenAPI 3.1 document.
 *
 * Composes the metadata catalogue and the document assembler (which in turn
 * drives the path, resource-schema, query-parameter, and error-response
 * builders and the field-type resolver) into a single emission: it assembles
 * the document for the requested audience and records a summary of what was
 * walked. The use case is pure orchestration over read-only metadata and schema
 * introspection; persistence is the command's concern, through the
 * DocumentWriter port.
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

        /** The catalogue of resource metadata to export. */
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
            resourceCount : count($this->catalogue->getResourceMap()),
            parameterCount: $this->countComponents($document, 'parameters'),
            responseCount : $this->countComponents($document, 'responses'),
        );
    }

    /**
     * Count the entries in a named components section of the assembled
     * document, tolerating a missing or non-array section.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $section
     * @return int
     */
    private function countComponents(array $document, string $section): int
    {
        $components = $document['components'] ?? [];

        if (!is_array($components) || !is_array($components[$section] ?? null)) {
            return 0;
        }

        return count($components[$section]);
    }
}
