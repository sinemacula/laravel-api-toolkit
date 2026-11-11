<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Name the OpenAPI tag an operation is grouped under.
 *
 * A method-level tag wins over a class-level one.
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
