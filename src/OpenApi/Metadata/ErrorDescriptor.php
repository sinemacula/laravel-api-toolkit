<?php

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

/**
 * Immutable descriptor for a single error code's OpenAPI contract.
 *
 * Carries the integer error code, its resolved HTTP status, and the
 * canonical title and detail strings sourced from the package language
 * file. Consumed by the error-response builder to assemble the
 * components.responses section of the emitted document.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ErrorDescriptor
{
    /**
     * Create a new error descriptor.
     *
     * @param  int  $code
     * @param  int  $httpStatus
     * @param  string|null  $title
     * @param  string  $detail
     */
    public function __construct(

        /** The integer error code, e.g. 10103 */
        public int $code,

        /** The resolved HTTP status code, e.g. 404 */
        public int $httpStatus,

        /** The canonical title string, or null when none is defined */
        public ?string $title,

        /** The canonical detail string */
        public string $detail,

    ) {}
}
