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
 * with named children is an object that may also be sent as a list unless a
 * child demands it be an object, its `array:` keys restricting the names it
 * admits, while a list ignores its named children. A field holding a child the
 * validator refuses to find missing, such as a required one, is itself required
 * and refuses null, though never through a wildcard, as a missing list has no
 * elements to validate, and a field beneath one an exclude rule may drop
 * demands nothing, as the validator skips it too. Each object then gains the
 * confirmation siblings and presence constraints its fields' confirmed and
 * conditional presence rules express, while a field that may never be sent is
 * the false schema, with no children and no constraint. The result is a `{type:
 * object, properties, required}` fragment inlined by the caller, never a
 * registered component; nothing here throws, so an unrecognised rule degrades
 * to a skipped constraint rather than a failure.
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
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\FieldPresence  $fields
     */
    public function __construct(

        /** The normaliser flattening a rule definition into tokens. */
        private RuleNormaliser $normaliser,

        /** The builder turning a field's tokens into a schema fragment. */
        private FieldSchemaBuilder $builder,

        /** The builder adding an object's confirmation and presence rules. */
        private PresenceConstraintBuilder $presence = new PresenceConstraintBuilder,

        /** The reader of how a field's rules govern its presence. */
        private FieldPresence $fields = new FieldPresence,
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

        $schema = $this->buildNode($this->withInheritedExclusion($normalised), '')['schema'];

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
        $tokens = $map[''] ?? [];
        $field  = $this->builder->build($tokens);

        if ($field['schema'] === false) {
            return $field;
        }

        $demands = $this->demandingChildren($map, $path);
        $schema  = $this->shapeNode($field['schema'], $this->groupChildren($map), $path, $tokens, $demands);

        return $demands === []
            ? ['schema' => $schema, 'required' => $field['required']]
            : ['schema' => $this->withoutNull($schema), 'required' => true];
    }

    /**
     * Shape a node's own schema by its children: array items for a wildcard
     * child, the object and list forms of an array field with named children,
     * or a nested object for any other field with children.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<array-key, array<array-key, list<string>>>  $grouped
     * @param  string  $path
     * @param  list<string>  $tokens
     * @param  array<array-key, bool>  $demands
     * @return array<string, mixed>
     */
    private function shapeNode(array $schema, array $grouped, string $path, array $tokens, array $demands): array
    {
        $isArray = in_array('array', (array) ($schema['type'] ?? []), true);

        if ($isArray) {
            $grouped = $this->withoutIndices($grouped);
        }

        $enclosed = in_array(true, $demands, true);

        return match (true) {
            isset($grouped['*'])            => $this->arrayNode($schema, $grouped['*'], $path),
            in_array('list', $tokens, true) => $schema,
            $isArray                        => $this->keyedArrayNode($schema, $grouped, $path, $tokens, $enclosed),
            $grouped !== []                 => $this->objectNode($schema, $grouped, $path, $tokens, $enclosed),
            default                         => $schema,
        };
    }

    /**
     * Collect the children of a node any of whose fields refuses every body
     * missing the node, so the node must be sent, each flagged when that field
     * also refuses the node holding no named key, so the node must be an object
     * holding it. A field beneath a wildcard is never one, as a missing list
     * has no elements to validate.
     *
     * @param  array<array-key, list<string>>  $map
     * @param  string  $path
     * @return array<array-key, bool>
     */
    private function demandingChildren(array $map, string $path): array
    {
        $demands = [];

        foreach ($map as $key => $tokens) {
            $segments = explode('.', (string) $key);

            if ($key === '' || in_array('*', $segments, true) || !$this->fields->demandsAncestor($tokens, $path)) {
                continue;
            }

            $named = (string) (int) $segments[0] !== $segments[0] && $this->fields->demandsNamedKey($tokens, $path);

            $demands[$segments[0]] = ($demands[$segments[0]] ?? false) || $named;
        }

        return $demands;
    }

    /**
     * Mark every field beneath one an exclude rule may drop as droppable too,
     * since the validator skips the fields beneath an excluded one, including
     * the elements a wildcard expands to at or beneath an excluded element.
     *
     * @param  array<array-key, list<string>>  $map
     * @return array<array-key, list<string>>
     */
    private function withInheritedExclusion(array $map): array
    {
        $excludable = array_keys(array_filter($map, $this->fields->isExcludable(...)));

        foreach ($map as $key => $tokens) {
            if (!$this->isWithinAny(explode('.', (string) $key), $excludable)) {
                continue;
            }

            $map[$key] = [...$tokens, 'exclude'];
        }

        return $map;
    }

    /**
     * Determine whether a path lies at or beneath any of the given paths.
     *
     * @param  list<string>  $path
     * @param  list<array-key>  $ancestors
     * @return bool
     */
    private function isWithinAny(array $path, array $ancestors): bool
    {
        foreach ($ancestors as $ancestor) {
            if ($this->isWithin($path, explode('.', (string) $ancestor))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a path lies at or beneath an ancestor path, segment by
     * segment, a wildcard segment of the path matching any key.
     *
     * @param  list<string>  $path
     * @param  list<string>  $ancestor
     * @return bool
     */
    private function isWithin(array $path, array $ancestor): bool
    {
        if (count($path) < count($ancestor)) {
            return false;
        }

        foreach ($ancestor as $depth => $segment) {
            if ($segment !== $path[$depth] && $path[$depth] !== '*') {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove null from a schema's types, since a node whose fields demand it be
     * sent refuses null as it refuses a missing node.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function withoutNull(array $schema): array
    {
        if (!is_array($schema['type'] ?? null)) {
            return $schema;
        }

        $types = array_values(array_filter($schema['type'], static fn (mixed $type): bool => $type !== 'null'));

        $schema['type'] = count($types) === 1 ? $types[0] : $types;

        return $schema;
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
     * The object admits null when its own rules do, and holds a property when
     * they refuse an empty value and no child demands one already.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<array-key, array<array-key, list<string>>>  $grouped
     * @param  string  $path
     * @param  list<string>  $tokens
     * @param  bool  $enclosed
     * @return array<string, mixed>
     */
    private function objectNode(array $schema, array $grouped, string $path, array $tokens, bool $enclosed): array
    {
        $properties = [];
        $required   = [];
        $children   = [];

        foreach ($grouped as $segment => $childMap) {
            $name  = (string) $segment;
            $child = $this->buildNode($childMap, $this->childPath($path, $name));

            $properties[$name] = $child['schema'];
            $children[$name]   = $childMap[''] ?? [];

            if (!$child['required']) {
                continue;
            }

            $required[] = $name;
        }

        $schema['type']       = $this->fields->acceptsNull($tokens) ? ['object', 'null'] : 'object';
        $schema['properties'] = $properties;

        if ($required !== []) {
            $schema['required'] = $required;
        } elseif (!$enclosed && $this->fields->rejectsEmpty($tokens)) {
            $schema['minProperties'] = 1;
        }

        return $this->presence->build($schema, $path, $children);
    }

    /**
     * Build an array field whose children, if any, name its keys: it is also an
     * object holding them, whose keys its `array:` rule restricts to the keys
     * it names, and may still be sent as a list, a list being an array carrying
     * no named key, unless a child demands the object hold one.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<array-key, array<array-key, list<string>>>  $grouped
     * @param  string  $path
     * @param  list<string>  $tokens
     * @param  bool  $enclosed
     * @return array<string, mixed>
     */
    private function keyedArrayNode(array $schema, array $grouped, string $path, array $tokens, bool $enclosed): array
    {
        $keys = $this->allowedKeys($tokens);

        if ($grouped === [] && $keys === null) {
            return $schema;
        }

        $object = $this->objectNode($schema, $grouped + array_fill_keys($keys ?? [], []), $path, $tokens, $enclosed);
        $object = $this->withPropertyCounts($keys === null ? $object : $this->restrictKeys($object, $keys));

        if ($enclosed) {
            unset($object['items'], $object['minItems'], $object['maxItems']);

            $object['type'] = 'object';

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
