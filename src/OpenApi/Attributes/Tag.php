<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Name the OpenAPI tag an operation is grouped under.
 *
 * Placed on a controller class or action method, this directive fixes the tag
 * for the routes it covers, overriding the tag the builder would otherwise
 * derive. A method-level tag wins over a class-level one, so a single
 * controller can spread its actions across more than one tag.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Tag
{
    /**
     * Create a new tag directive.
     *
     * @param  string  $name
     */
    public function __construct(

        /** The tag name the operation is grouped under. */
        public string $name,
    ) {}
}
