<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Contract for a class that describes its own OpenAPI schema.
 *
 * Exported as a named component, referenced wherever a #[ResponseSchema] names
 * the class. As one member of a union, an object schema requiring a string
 * `const`, under the same name and distinct in each member, adds a
 * discriminator. An enum shape fails the export when a registered resource's
 * field or a documented request rule also uses the enum.
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
