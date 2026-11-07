<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Translates a Laravel validation rules array into a JSON-Schema object.
 *
 * The rules array is the single source the request-body documentation is built
 * from. Each field's rules are normalised to tokens and turned into a property
 * schema, while dotted keys weave the properties into nested objects and a
 * `field.*` sibling supplies the items schema of an array field. An array field
 * with named children is an object that may also be sent as a list unless it
 * requires a child, its `array:` keys restricting the names it admits, while a
 * list ignores its named children. Each object then gains the confirmation
 * siblings and presence constraints its fields' confirmed and conditional
 * presence rules express, while a field that may never be sent is the false
 * schema, with no children and no constraint. The result is a `{type: object,
 * properties, required}` fragment inlined by the caller, never a registered
 * component; nothing here throws, so an unrecognised rule degrades to a skipped
 * constraint rather than a failure.
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
     * @param  array<array-key, list<string>>  $map
     * @param  string  $path
     * @return array{schema: array<string, mixed>|false, required: bool}
     */
    private function buildNode(array $map, string $path): array
    {
        $tokens  = $map[''] ?? [];
        $field   = $this->builder->build($tokens);
        $schema  = $field['schema'];
        $grouped = $this->groupChildren($map);
        $isArray = is_array($schema) && in_array('array', (array) ($schema['type'] ?? []), true);

        if ($isArray) {
            $grouped = $this->withoutIndices($grouped);
        }

        $schema = match (true) {
            $schema === false               => false,
            isset($grouped['*'])            => $this->arrayNode($schema, $grouped['*'], $path),
            in_array('list', $tokens, true) => $schema,
            $isArray                        => $this->keyedArrayNode($schema, $grouped, $path, $tokens),
            $grouped !== []                 => $this->objectNode($schema, $grouped, $path),
            default                         => $schema,
        };

        return ['schema' => $schema, 'required' => $field['required']];
    }

    /**
     * Group a path map's children by their leading segment, dropping the node's
     * own entry.
     *
     * @param  array<array-key, list<string>>  $map
     * @return array<array-key, array<array-key, list<string>>>
     */
    private function groupChildren(array $map): array
    {
        $grouped = [];

        foreach ($map as $path => $tokens) {
            if ($path === '') {
                continue;
            }

            [$segment, $rest] = $this->splitFirst((string) $path);

            $grouped[$segment][$rest] = $tokens;
        }

        return $grouped;
    }

    /**
     * Drop the children an array field keys by a list index, which constrain a
     * single element rather than name a property, so a list the rules accept is
     * never documented as an object.
     *
     * @param  array<array-key, array<array-key, list<string>>>  $grouped
     * @return array<array-key, array<array-key, list<string>>>
     */
    private function withoutIndices(array $grouped): array
    {
        return array_filter($grouped, is_string(...), ARRAY_FILTER_USE_KEY);
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
     * @param  array<array-key, array<array-key, list<string>>>  $grouped
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
     * Build an array field whose children, if any, name its keys: it is also an
     * object holding them, whose keys its `array:` rule restricts to the keys
     * it names, and may still be sent as a list, a list being an array carrying
     * no named key, unless the object requires one.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<array-key, array<array-key, list<string>>>  $grouped
     * @param  string  $path
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    private function keyedArrayNode(array $schema, array $grouped, string $path, array $tokens): array
    {
        $keys = $this->allowedKeys($tokens);

        if ($grouped === [] && $keys === null) {
            return $schema;
        }

        $object = $this->objectNode($schema, $grouped + array_fill_keys($keys ?? [], []), $path);
        $object = $this->withPropertyCounts($keys === null ? $object : $this->restrictKeys($object, $keys));

        if (isset($object['required'])) {
            unset($object['items'], $object['minItems'], $object['maxItems']);

            return $object;
        }

        $object['type'] = ['object', ...(array) $schema['type']];

        if ($keys !== null) {
            $object['maxItems'] = min($object['maxItems'] ?? PHP_INT_MAX, $this->listLength($keys));
        }

        return $object;
    }

    /**
     * Bound an array field's object form by the element counts its size rules
     * set, since the validator counts an object's keys as it counts a list's
     * elements.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function withPropertyCounts(array $object): array
    {
        foreach (['minItems' => 'minProperties', 'maxItems' => 'maxProperties'] as $items => $properties) {
            if (!isset($object[$items])) {
                continue;
            }

            $object[$properties] = $object[$items];
        }

        return $object;
    }

    /**
     * Read the keys an `array:` rule restricts the field to, as the validator
     * parses them, or null when no rule restricts its keys.
     *
     * @param  list<string>  $tokens
     * @return list<string>|null
     */
    private function allowedKeys(array $tokens): ?array
    {
        foreach ($tokens as $token) {
            if (str_starts_with($token, 'array:')) {
                return array_map(static fn (?string $key): string => (string) $key, str_getcsv(substr($token, 6), ',', '"', '\\'));
            }
        }

        return null;
    }

    /**
     * Restrict an object to the allowed keys, forbidding any declared property
     * the keys leave out and any undeclared one.
     *
     * @param  array<string, mixed>  $object
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function restrictKeys(array $object, array $keys): array
    {
        /** @var array<array-key, mixed> $properties */
        $properties = $object['properties'];

        foreach (array_keys($properties) as $name) {
            if (in_array((string) $name, $keys, true)) {
                continue;
            }

            $properties[$name] = false;
        }

        $object['properties']           = $properties;
        $object['additionalProperties'] = false;

        return $object;
    }

    /**
     * Count the leading list indices the allowed keys name, the longest list
     * the key restriction admits.
     *
     * @param  list<string>  $keys
     * @return int
     */
    private function listLength(array $keys): int
    {
        $length = 0;

        while (in_array((string) $length, $keys, true)) {
            $length++;
        }

        return $length;
    }

    /**
     * Fold the `field.*` subtree into an array schema's items node, requiring
     * both it and any item constraint the array's own rules already set.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<array-key, list<string>>  $starMap
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
