<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Translates a Laravel validation rules array into a JSON-Schema object.
 *
 * The rules array is the single source the request-body documentation is built
 * from. Each field's rules are normalised to tokens and turned into a property
 * schema, while dotted keys weave the properties into nested objects and a
 * `field.*` sibling supplies the items schema of an array field. A confirmed
 * field gains a matching `<field>_confirmation` sibling of the same base type.
 * The result is a `{type: object, properties, required}` fragment inlined by
 * the caller, never a registered component; nothing here throws, so an
 * unrecognised rule degrades to a skipped constraint rather than a failure.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RulesToSchemaTranslator
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser  $normaliser
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder  $builder
     */
    public function __construct(

        /** The normaliser flattening a rule definition into tokens. */
        private RuleNormaliser $normaliser,

        /** The builder turning a field's tokens into a schema fragment. */
        private FieldSchemaBuilder $builder,
    ) {}

    /**
     * Translate a Laravel rules array into a JSON-Schema object fragment.
     *
     * @param  array<array-key, mixed>  $rules
     * @return array<string, mixed>
     */
    public function translate(array $rules): array
    {
        $normalised = [];

        foreach ($rules as $field => $definition) {
            $normalised[(string) $field] = $this->normaliser->normalise($definition);
        }

        return $this->buildNode($normalised)['schema'];
    }

    /**
     * Build the schema node for a path subtree, folding in nested object
     * properties or array items when the subtree carries children.
     *
     * @param  array<string, list<string>>  $map
     * @return array{schema: array<string, mixed>, required: bool}
     */
    private function buildNode(array $map): array
    {
        $field   = $this->builder->build($map[''] ?? []);
        $schema  = $field['schema'];
        $grouped = $this->groupChildren($map);

        if ($grouped !== []) {
            $schema = isset($grouped['*'])
                ? $this->arrayNode($schema, $grouped['*'])
                : $this->objectNode($schema, $grouped);
        }

        return ['schema' => $schema, 'required' => $field['required']];
    }

    /**
     * Group a path map's children by their leading segment, dropping the node's
     * own entry.
     *
     * @param  array<string, list<string>>  $map
     * @return array<string, array<string, list<string>>>
     */
    private function groupChildren(array $map): array
    {
        $grouped = [];

        foreach ($map as $path => $tokens) {
            if ($path === '') {
                continue;
            }

            [$segment, $rest] = $this->splitFirst($path);

            $grouped[$segment][$rest] = $tokens;
        }

        return $grouped;
    }

    /**
     * Split a dotted path into its leading segment and the remainder.
     *
     * @param  string  $path
     * @return array{0: string, 1: string}
     */
    private function splitFirst(string $path): array
    {
        $position = strpos($path, '.');

        if ($position === false) {
            return [$path, ''];
        }

        return [substr($path, 0, $position), substr($path, $position + 1)];
    }

    /**
     * Fold the grouped children into an object schema's properties, appending a
     * confirmation sibling for each confirmed field.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, list<string>>>  $grouped
     * @return array<string, mixed>
     */
    private function objectNode(array $schema, array $grouped): array
    {
        $properties = [];
        $required   = [];

        foreach ($grouped as $segment => $childMap) {
            $name  = $segment;
            $child = $this->buildNode($childMap);

            $properties[$name] = $child['schema'];

            if ($child['required']) {
                $required[] = $name;
            }

            if (!in_array('confirmed', $childMap[''] ?? [], true)) {
                continue;
            }

            $properties[$name . '_confirmation'] = $this->confirmationSchema($child['schema']);
        }

        $schema['type']       = 'object';
        $schema['properties'] = $properties;

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Fold the `field.*` subtree into an array schema's items node.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, list<string>>  $starMap
     * @return array<string, mixed>
     */
    private function arrayNode(array $schema, array $starMap): array
    {
        $schema['type']  = 'array';
        $schema['items'] = $this->buildNode($starMap)['schema'];

        return $schema;
    }

    /**
     * Build the schema for a confirmation sibling, mirroring the base type of
     * the confirmed field.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    private function confirmationSchema(array $schema): array
    {
        $type = $schema['type'] ?? null;

        return is_string($type) ? ['type' => $type] : ['type' => 'string'];
    }
}
