<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Contract for a class that describes its own OpenAPI schema.
 *
 * A class implementing this contract returns a JSON-Schema fragment describing
 * its shape, which the exporter inlines as the inner `data` body of a
 * non-resource response. Inlining keeps the fragment self-contained, so a
 * declared shape never depends on a separately registered component and can
 * never leave a dangling reference behind.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DefinesSchema
{
    /**
     * Return the JSON-Schema fragment describing the class shape.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array;
}
