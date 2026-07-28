<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Declare the request body of a write action from a rules source.
 *
 * Placed on an action method, this directive names the class whose validation
 * rules describe the request body, so the exporter can document a concrete
 * payload rather than leaving the write operation bodiless. The named class is
 * a self-describing input declaring static rules(), a Payload, or a plain
 * FormRequest; its rules are translated into an inlined JSON-Schema body. This
 * directive always wins over a type-hinted rules-source parameter on the same
 * action.
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
