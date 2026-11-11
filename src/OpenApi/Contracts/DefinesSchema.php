<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Contract for a class that describes its own OpenAPI schema.
 *
 * As one member of a union, declare a required `const` property so clients can
 * tell the shapes apart.
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
