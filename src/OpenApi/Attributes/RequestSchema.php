<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Declare the request body of a write action from a rules source.
 *
 * The class may declare static rules(), be a Payload or a FormRequest, and wins
 * over a type-hinted rules-source parameter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class RequestSchema
{
    /**
     * Create a new request-schema directive.
     *
     * @param  string  $schema
     */
    public function __construct(

        /** The class whose validation rules describe the request body. */
        public string $schema,
    ) {}
}
