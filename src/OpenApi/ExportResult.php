<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

/**
 * Immutable result of an OpenAPI components export.
 *
 * Carries the assembled document array together with a summary of what the
 * emission walked: the number of resources, query parameters, and error
 * responses emitted. The command reads the summary to report progress and to
 * surface the resource-count fail signal (a zero count means the emission ran
 * without consuming any metadata).
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
     * @param  int  $resourceCount
     * @param  int  $parameterCount
     * @param  int  $responseCount
     */
    public function __construct(

        /** The assembled OpenAPI 3.1 components document */
        public array $document,

        /** The number of resource component schemas emitted */
        public int $resourceCount,

        /** The number of shared query-parameter components emitted */
        public int $parameterCount,

        /** The number of error-response components emitted */
        public int $responseCount,

    ) {}
}
