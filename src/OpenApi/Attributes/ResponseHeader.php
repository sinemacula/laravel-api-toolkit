<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Declare a header a response carries.
 *
 * On an action or controller it documents the success response; on an
 * ApiException subclass, the error response, inherited by its subclasses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class ResponseHeader
{
    /** An HTTP field name token, other than an integer an array key would coerce */
    private const string FIELD_NAME_PATTERN = '/^(?!-?[0-9]+$)[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /**
     * Create a new response-header directive.
     *
     * @param  string  $name
     * @param  string  $description
     * @param  array<array-key, mixed>  $schema
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(

        /** The name of the header. */
        public string $name,

        /** The description of the header, or an empty string for none. */
        public string $description = '',

        /** The JSON-Schema fragment describing the header's value. */
        public array $schema = ['type' => 'string'],
    ) {
        if (preg_match(self::FIELD_NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('A response header name must be a valid HTTP field name, [%s] given', $name));
        }

        if (strcasecmp($name, 'Content-Type') === 0) {
            throw new \InvalidArgumentException('A response header cannot document Content-Type, which the response media type describes');
        }

        if (array_is_list($schema)) {
            throw new \InvalidArgumentException(sprintf('The schema of response header [%s] must be a JSON-Schema object', $name));
        }
    }
}
