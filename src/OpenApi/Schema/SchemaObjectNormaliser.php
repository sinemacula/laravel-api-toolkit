<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Rewrites the empty schemas of an assembled document as empty objects.
 *
 * The document is built from PHP arrays, and an empty array encodes as a JSON
 * array, which is neither a JSON Schema nor a valid keyword map. This pass
 * finds every schema position - a `schema` value, a `components.schemas` entry,
 * and every subschema a schema nests - and replaces an empty one, or an empty
 * map of named subschemas such as `properties`, with an empty object, so it
 * encodes as `{}`. Boolean schemas and example values are left untouched.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SchemaObjectNormaliser
{
    /** The keywords whose value is a single subschema */
    private const array SCHEMA_KEYWORDS = [
        'items', 'not', 'additionalProperties', 'contains', 'propertyNames', 'if', 'then', 'else',
        'unevaluatedItems', 'unevaluatedProperties', 'contentSchema',
    ];

    /** The keywords whose value is a list of subschemas */
    private const array SCHEMA_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** The keywords whose value is a map of named subschemas */
    private const array SCHEMA_MAP_KEYWORDS = ['properties', 'patternProperties', 'dependentSchemas', '$defs'];

    /** The keywords holding example data rather than document structure */
    private const array EXAMPLE_KEYWORDS = ['example', 'examples'];

    /** The keywords whose value maps names, never keywords, to objects */
    private const array NAMED_MAP_KEYWORDS = [
        'paths', 'webhooks', 'responses', 'headers', 'content', 'encoding', 'callbacks', 'links',
    ];

    /**
     * Normalise every schema the document carries.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function normalise(array $document): array
    {
        return $this->walk($document);
    }

    /**
     * Normalise the components block, each entry of which is a map of named
     * components, its schemas a map of schemas.
     *
     * @param  array<array-key, mixed>  $components
     * @return array<array-key, mixed>
     */
    private function components(array $components): array
    {
        foreach ($components as $key => $value) {
            if (!is_array($value) || in_array($key, self::EXAMPLE_KEYWORDS, true)) {
                continue;
            }

            $components[$key] = $key === 'schemas' ? $this->schemaMap($value) : array_map($this->entry(...), $value);
        }

        return $components;
    }

    /**
     * Walk a non-schema node, normalising the schema under each `schema` key
     * and the components block, and reading the entries of a named map by their
     * structure, never their name.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node): array
    {
        foreach ($node as $key => $value) {
            if (!is_array($value) || in_array($key, self::EXAMPLE_KEYWORDS, true)) {
                continue;
            }

            $node[$key] = match (true) {
                $key === 'schema'                              => $this->schema($value),
                $key === 'components'                          => $this->components($value),
                in_array($key, self::NAMED_MAP_KEYWORDS, true) => array_map($this->entry(...), $value),
                default                                        => $this->walk($value),
            };
        }

        return $node;
    }

    /**
     * Walk an entry of a named map, leaving a non-array value as is.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function entry(mixed $value): mixed
    {
        return is_array($value) ? $this->walk($value) : $value;
    }

    /**
     * Normalise a schema and the subschemas it nests, returning an empty object
     * for an empty schema.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>|\stdClass
     */
    private function schema(array $schema): array|\stdClass
    {
        if ($schema === []) {
            return new \stdClass;
        }

        foreach ($schema as $keyword => $value) {
            if (!is_array($value)) {
                continue;
            }

            $schema[$keyword] = match (true) {
                in_array($keyword, self::SCHEMA_KEYWORDS, true)      => $this->schema($value),
                in_array($keyword, self::SCHEMA_LIST_KEYWORDS, true) => array_map($this->subschema(...), $value),
                in_array($keyword, self::SCHEMA_MAP_KEYWORDS, true)  => $this->schemaMap($value),
                default                                              => $value,
            };
        }

        return $schema;
    }

    /**
     * Normalise a map of named schemas, returning an empty object for an empty
     * map.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<array-key, mixed>|\stdClass
     */
    private function schemaMap(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass : array_map($this->subschema(...), $map);
    }

    /**
     * Normalise a subschema, leaving a boolean or other non-array value as is.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function subschema(mixed $value): mixed
    {
        return is_array($value) ? $this->schema($value) : $value;
    }
}
