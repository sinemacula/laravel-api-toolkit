<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Contract for a class that describes its own OpenAPI schema.
 *
 * Exported as a named component, referenced wherever a #[ResponseSchema] names
 * the class. As one member of a union, a required string `const`, under the
 * same name and distinct in each member, adds a discriminator.
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
