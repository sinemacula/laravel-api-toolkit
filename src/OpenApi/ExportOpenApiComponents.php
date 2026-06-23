<?php

namespace SineMacula\ApiToolkit\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;

/**
 * Application use case that exports the toolkit's OpenAPI 3.1 components.
 *
 * Composes the metadata catalogue and the document assembler (which in turn
 * drives the resource-schema, query-parameter, and error-response builders and
 * the field-type resolver) into a single emission: it assembles the
 * components-only document and records a summary of what was walked. The use
 * case is pure orchestration over read-only metadata and schema introspection;
 * persistence is the command's concern, through the DocumentWriter port.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExportOpenApiComponents
{
    /**
     * Create a new export use case.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\OpenApiAssembler  $assembler
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     */
    public function __construct(
        private readonly OpenApiAssembler $assembler,
        private readonly MetadataCatalogue $catalogue,
    ) {}

    /**
     * Assemble the components document and summarise the emission.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    public function export(): ExportResult
    {
        $document = $this->assembler->assemble();

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
