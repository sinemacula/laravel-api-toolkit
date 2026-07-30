<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

/**
 * Immutable descriptor for a single error code's OpenAPI contract.
 *
 * Carries the integer error code, its resolved HTTP status, and the canonical
 * title and detail strings sourced from the package language file. Consumed by
 * the error-response builder to assemble the components.responses section of
 * the emitted document. The owning exception class is carried so the
 * documentation can group errors by the module the exception belongs to, and is
 * null for a code with no discoverable owning exception.
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
     * @param  string|null  $source
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

        /** The owning exception class, or null when none is known */
        public ?string $source = null,
    ) {}
}
