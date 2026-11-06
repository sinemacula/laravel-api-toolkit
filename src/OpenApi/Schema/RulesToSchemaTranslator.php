<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Translates a Laravel validation rules array into a JSON-Schema object.
 *
 * The rules array is the single source the request-body documentation is built
 * from. Each field's rules are normalised to tokens and turned into a property
 * schema, while dotted keys weave the properties into nested objects and a
 * `field.*` sibling supplies the items schema of an array field. Each object
 * then gains the confirmation siblings and presence constraints its fields'
 * confirmed and conditional presence rules express, while a field that may
 * never be sent is the false schema, with no children and no constraint. The
 * result is a `{type: object, properties, required}` fragment inlined by the
 * caller, never a registered component; nothing here throws, so an unrecognised
 * rule degrades to a skipped constraint rather than a failure.
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
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\PresenceConstraintBuilder  $presence
     */
    public function __construct(

        /** The normaliser flattening a rule definition into tokens. */
        private RuleNormaliser $normaliser,

        /** The builder turning a field's tokens into a schema fragment. */
        private FieldSchemaBuilder $builder,

        /** The builder adding an object's confirmation and presence rules. */
        private PresenceConstraintBuilder $presence = new PresenceConstraintBuilder,
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

        $schema = $this->buildNode($normalised, '')['schema'];

        return $schema === false ? [] : $schema;
    }

    /**
     * Build the schema node for a path subtree, folding in nested object
     * properties or array items when the subtree carries children.
     *
     * @param  array<string, list<string>>  $map
     * @param  string  $path
     * @return array{schema: array<string, mixed>|false, required: bool}
     */
    private function buildNode(array $map, string $path): array
    {
        $field   = $this->builder->build($map[''] ?? []);
        $schema  = $field['schema'];
        $grouped = $this->groupChildren($map);

        if ($schema !== false && $grouped !== []) {
            $schema = isset($grouped['*'])
                ? $this->arrayNode($schema, $grouped['*'], $path)
                : $this->objectNode($schema, $grouped, $path);
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
     * Join a child segment onto its parent's dotted path.
     *
     * @param  string  $path
     * @param  string  $segment
     * @return string
     */
    private function childPath(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path . '.' . $segment;
    }

    /**
     * Fold the grouped children into an object schema's properties, then add
     * the confirmation siblings and presence constraints their rules express.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<array-key, array<string, list<string>>>  $grouped
     * @param  string  $path
     * @return array<string, mixed>
     */
    private function objectNode(array $schema, array $grouped, string $path): array
    {
        $properties = [];
        $required   = [];
        $tokens     = [];

        foreach ($grouped as $segment => $childMap) {
            $name  = (string) $segment;
            $child = $this->buildNode($childMap, $this->childPath($path, $name));

            $properties[$name] = $child['schema'];
            $tokens[$name]     = $childMap[''] ?? [];

            if (!$child['required']) {
                continue;
            }

            $required[] = $name;
        }

        $schema['type']       = 'object';
        $schema['properties'] = $properties;

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $this->presence->build($schema, $path, $tokens);
    }

    /**
     * Fold the `field.*` subtree into an array schema's items node, requiring
     * both it and any item constraint the array's own rules already set.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, list<string>>  $starMap
     * @param  string  $path
     * @return array<string, mixed>
     */
    private function arrayNode(array $schema, array $starMap, string $path): array
    {
        $own   = $schema['items'] ?? null;
        $items = $this->buildNode($starMap, $this->childPath($path, '*'))['schema'];

        $schema['type']  = 'array';
        $schema['items'] = match (true) {
            !is_array($own) => $items,
            $items === []   => $own,
            default         => ['allOf' => [$own, $items]],
        };

        return $schema;
    }
}
