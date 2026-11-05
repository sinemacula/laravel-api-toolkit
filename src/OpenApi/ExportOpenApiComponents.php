<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;

/**
 * Application use case that exports the toolkit's OpenAPI 3.1 document.
 *
 * Composes the metadata catalogue and the document assembler (which in turn
 * drives the path, resource-schema, query-parameter, and error-response
 * builders and the field-type resolver) into a single emission: it assembles
 * the document for the requested audience and records a summary of what the
 * document contains. Every count is read from the assembled document, so a
 * resource schema pruned from the audience is not counted. The use case is pure
 * orchestration over read-only metadata and schema introspection; persistence
 * is the command's concern, through the DocumentWriter port.
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
     * Count the operations across every path item of the assembled document,
     * tolerating a non-array paths object or path item.
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
     * Count the resource component schemas the assembled document contains,
     * leaving out the envelope and enum schemas sharing its schemas section.
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
     * Count the entries in a named components section of the assembled
     * document, tolerating a missing or non-array section.
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
     * Resolve a named components section of the assembled document, treating a
     * missing or non-array section as empty.
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
