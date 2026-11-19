<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Rewrites the empty schemas of an assembled document as empty objects.
 *
 * An empty PHP array encodes as a JSON array, which is not a valid schema or
 * keyword map.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SchemaObjectNormaliser
{
    /** The keywords whose value maps property names to lists of names */
    private const array NAME_MAP_KEYWORDS = ['dependentRequired'];

    /** The keyword whose mapping keys discriminator values to references */
    private const string DISCRIMINATOR_KEYWORD = 'discriminator';

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
     * Normalise the components block.
     *
     * @param  array<array-key, mixed>  $components
     * @return array<array-key, mixed>
     */
    private function components(array $components): array
    {
        foreach ($components as $key => $value) {
            if (!is_array($value) || in_array($key, SchemaKeywords::EXAMPLE, true)) {
                continue;
            }

            $components[$key] = $key === 'schemas' ? $this->schemaMap($value) : array_map($this->entry(...), $value);
        }

        return $components;
    }

    /**
     * Walk a non-schema node, normalising each schema it carries.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node): array
    {
        foreach ($node as $key => $value) {
            if (!is_array($value) || in_array($key, SchemaKeywords::EXAMPLE, true)) {
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
     * Normalise a schema and the subschemas it nests.
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
                in_array($keyword, SchemaKeywords::SCHEMA, true)      => $this->schema($value),
                in_array($keyword, SchemaKeywords::SCHEMA_LIST, true) => array_map($this->subschema(...), $value),
                in_array($keyword, SchemaKeywords::SCHEMA_MAP, true)  => $this->schemaMap($value),
                in_array($keyword, self::NAME_MAP_KEYWORDS, true)     => $this->nameMap($value),
                $keyword === self::DISCRIMINATOR_KEYWORD              => $this->discriminator($value),
                default                                               => $value,
            };
        }

        return $schema;
    }

    /**
     * Normalise a map of named schemas.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<array-key, mixed>|\stdClass
     */
    private function schemaMap(array $map): array|\stdClass
    {
        return $this->nameMap(array_map($this->subschema(...), $map));
    }

    /**
     * Normalise a discriminator's mapping as a name map.
     *
     * @param  array<array-key, mixed>  $discriminator
     * @return array<array-key, mixed>
     */
    private function discriminator(array $discriminator): array
    {
        if (is_array($discriminator['mapping'] ?? null)) {
            $discriminator['mapping'] = $this->nameMap($discriminator['mapping']);
        }

        return $discriminator;
    }

    /**
     * Return a list-shaped name map as an object so it encodes as one.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<array-key, mixed>|\stdClass
     */
    private function nameMap(array $map): array|\stdClass
    {
        return array_is_list($map) ? (object) $map : $map;
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
