<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

/**
 * Finds the first union of a body that offers several value shapes, names its
 * shapes, and lists the alternatives of a union that constrain a value.
 *
 * A union whose alternatives carry only presence rules, or that only adds null,
 * offers one shape.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ShapeUnionFinder
{
    /** The keywords whose alternatives offer a value each */
    private const array UNION_KEYWORDS = ['oneOf', 'anyOf'];

    /** The keywords that make an alternative a value shape */
    private const array SHAPE_KEYWORDS = ['$ref', 'type', 'const', 'enum', 'properties'];

    /** The schema admitting only null */
    private const array NULL_SCHEMA = ['type' => 'null'];

    /** The keywords an alternative carrying only presence rules is made of */
    private const array PRESENCE_KEYWORDS = ['required', 'dependentRequired', 'minProperties', 'maxProperties'];

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument  $schemas
     */
    public function __construct(

        /** The document the body's schema belongs to. */
        private SchemaDocument $schemas,
    ) {}

    /**
     * Find the first shape union of a body, depth first through references,
     * conjuncts, required properties and items, with the pointers of its shapes
     * other than null.
     *
     * @param  string  $pointer
     * @param  list<string>  $seen
     * @return array{string, list<string>}|null
     */
    public function find(string $pointer, array $seen = []): ?array
    {
        $node = $this->schemas->node($pointer);

        if (!is_array($node) || in_array($pointer, $seen, true)) {
            return null;
        }

        $union = $this->shapes($pointer, $node);

        foreach ($union === null ? $this->children($pointer, $node) : [] as $child) {
            $union ??= $this->find($child, [...$seen, $pointer]);
        }

        return $union;
    }

    /**
     * List the pointers of a node's union alternatives that constrain a value,
     * being neither null alone nor presence rules alone.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    public function valueAlternatives(string $pointer, array $node): array
    {
        $alternatives = [];

        foreach (self::UNION_KEYWORDS as $keyword) {
            foreach (is_array($node[$keyword] ?? null) ? $node[$keyword] : [] as $index => $alternative) {

                if ($alternative === self::NULL_SCHEMA || $this->isPresenceOnly($alternative)) {
                    continue;
                }

                $alternatives[] = SchemaDocument::append(SchemaDocument::append($pointer, $keyword), $index);
            }
        }

        return $alternatives;
    }

    /**
     * Name a shape by its title, its target's title or component name, else its
     * position, suffixed with an ordinal when the name is taken.
     *
     * @param  string  $pointer
     * @param  int  $position
     * @param  array<string, mixed>  $taken
     * @return string
     */
    public function name(string $pointer, int $position, array $taken): string
    {
        $name   = $this->label($pointer, $position);
        $unique = $name;

        for ($ordinal = 2; array_key_exists($unique, $taken); $ordinal++) {
            $unique = $name . ' ' . $ordinal;
        }

        return $unique;
    }

    /**
     * Label a shape by its title, its target's title or component name, else
     * its position.
     *
     * @param  string  $pointer
     * @param  int  $position
     * @return string
     */
    private function label(string $pointer, int $position): string
    {
        $node      = (array) $this->schemas->node($pointer);
        $reference = is_string($node['$ref'] ?? null) ? $node['$ref'] : null;
        $target    = $reference === null ? [] : (array) $this->schemas->node(substr($reference, 1));

        return match (true) {
            is_string($node['title'] ?? null)   => $node['title'],
            is_string($target['title'] ?? null) => $target['title'],
            $reference !== null                 => str_replace(['~1', '~0'], ['/', '~'], substr((string) strrchr($reference, '/'), 1)),
            default                             => 'Alternative ' . ($position + 1),
        };
    }

    /**
     * Read the shape union a node itself holds, with the pointers of its shapes
     * other than null.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @return array{string, list<string>}|null
     */
    private function shapes(string $pointer, array $node): ?array
    {
        foreach (self::UNION_KEYWORDS as $keyword) {

            $shapes = $this->shapesOf(SchemaDocument::append($pointer, $keyword), $node[$keyword] ?? null);

            if (count($shapes) > 1) {
                return [$pointer, $shapes];
            }
        }

        return null;
    }

    /**
     * List the pointers of a union's alternatives other than null, none when
     * any alternative constrains no value.
     *
     * @param  string  $pointer
     * @param  mixed  $alternatives
     * @return list<string>
     */
    private function shapesOf(string $pointer, mixed $alternatives): array
    {
        $shapes = [];

        foreach (is_array($alternatives) ? $alternatives : [] as $index => $alternative) {

            if (!is_array($alternative) || array_intersect_key($alternative, array_flip(self::SHAPE_KEYWORDS)) === []) {
                return [];
            }

            if ($alternative === self::NULL_SCHEMA) {
                continue;
            }

            $shapes[] = SchemaDocument::append($pointer, $index);
        }

        return $shapes;
    }

    /**
     * List the nodes a shape union is searched for beneath a node: its
     * reference, its conjuncts, its required properties and its items.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private function children(string $pointer, array $node): array
    {
        $children = is_string($node['$ref'] ?? null) ? [substr($node['$ref'], 1)] : [];

        foreach (array_keys(is_array($node['allOf'] ?? null) ? $node['allOf'] : []) as $index) {
            $children[] = SchemaDocument::append(SchemaDocument::append($pointer, 'allOf'), $index);
        }

        $children = [...$children, ...$this->requiredProperties($pointer, $node)];

        if (array_key_exists('items', $node)) {
            $children[] = SchemaDocument::append($pointer, 'items');
        }

        return $children;
    }

    /**
     * List the pointers of a node's required properties, in declaration order.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private function requiredProperties(string $pointer, array $node): array
    {
        $required   = array_filter((array) ($node['required'] ?? []), is_string(...));
        $properties = [];

        foreach (array_keys(is_array($node['properties'] ?? null) ? $node['properties'] : []) as $name) {

            if (!in_array((string) $name, $required, true)) {
                continue;
            }

            $properties[] = SchemaDocument::append(SchemaDocument::append($pointer, 'properties'), $name);
        }

        return $properties;
    }

    /**
     * Determine whether a schema carries only presence rules.
     *
     * @param  mixed  $schema
     * @return bool
     */
    private function isPresenceOnly(mixed $schema): bool
    {
        return is_array($schema)
            && $schema !== []
            && array_diff_key($schema, array_flip(self::PRESENCE_KEYWORDS)) === [];
    }
}
