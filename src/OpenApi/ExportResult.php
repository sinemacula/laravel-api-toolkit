<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

/**
 * Immutable result of an OpenAPI components export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExportResult
{
    /**
     * Create a new export result.
     *
     * @param  array<string, mixed>  $document
     * @param  int  $operationCount
     * @param  int  $resourceCount
     * @param  int  $parameterCount
     * @param  int  $responseCount
     */
    public function __construct(

        /** The assembled OpenAPI 3.1 components document */
        public array $document,

        /** The number of path operations emitted across every path item */
        public int $operationCount,

        /** The number of resource component schemas the document contains */
        public int $resourceCount,

        /** The number of shared query-parameter components emitted */
        public int $parameterCount,

        /** The number of error-response components emitted */
        public int $responseCount,
    ) {}
}
