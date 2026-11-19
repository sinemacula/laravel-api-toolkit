<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Names the JSON Schema keywords that nest subschemas, by the shape of their
 * value.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SchemaKeywords
{
    /** The keywords whose value is a single subschema */
    public const array SCHEMA = [
        'items', 'not', 'additionalProperties', 'contains', 'propertyNames', 'if', 'then', 'else',
        'unevaluatedItems', 'unevaluatedProperties', 'contentSchema',
    ];

    /** The keywords whose value is a list of subschemas */
    public const array SCHEMA_LIST = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** The keywords whose value is a map of named subschemas */
    public const array SCHEMA_MAP = ['properties', 'patternProperties', 'dependentSchemas', '$defs'];

    /** The keywords holding example data rather than document structure */
    public const array EXAMPLE = ['example', 'examples'];
}
