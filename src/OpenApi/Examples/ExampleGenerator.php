<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

use SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope;
use SineMacula\ApiToolkit\OpenApi\Schema\PresenceConstraintBuilder;

/**
 * Generates deterministic example values from the schemas of an assembled
 * document.
 *
 * A node's candidates come, in order, from the values it lists, its reference,
 * its unions and its type, and the first the node accepts is taken; nothing is
 * random or guessed from a name. A body whose first shape union offers several
 * value shapes gets one example per shape.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExampleGenerator
{
    /** The keywords whose alternatives offer a value each */
    private const array UNION_KEYWORDS = ['oneOf', 'anyOf'];

    /** The schema admitting only null */
    private const array NULL_SCHEMA = ['type' => 'null'];

    /** @var \SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope The body the current example is generated for. */
    private ExampleScope $scope = ExampleScope::RESPONSE;

    /** @var array{string, string}|null The shape union being expanded and the shape it is held to. */
    private ?array $held = null;

    /** @var \SineMacula\ApiToolkit\OpenApi\Examples\ShapeUnionFinder The finder of a body's shape union. */
    private readonly ShapeUnionFinder $unions;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument  $schemas
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\LiteralValues  $literals
     */
    public function __construct(

        /** The document whose schemas the values are generated from. */
        private readonly SchemaDocument $schemas,

        /** The reader of the values a node lists or its scalar type admits. */
        private readonly LiteralValues $literals = new LiteralValues,
    ) {
        $this->unions = new ShapeUnionFinder($schemas);
    }

    /**
     * Generate the named examples of the body schema at a JSON pointer: one per
     * shape of its first shape union, else one named default.
     *
     * @param  string  $pointer
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope  $scope
     * @return array<string, mixed>|\SineMacula\ApiToolkit\OpenApi\Examples\MissingValue
     */
    public function generate(string $pointer, ExampleScope $scope): array|MissingValue
    {
        $this->scope = $scope;
        $this->held  = null;

        $union = $this->unions->find($pointer);

        if ($union === null) {
            $value = $this->value($pointer, []);

            return $value instanceof MissingValue ? $value : ['default' => $value];
        }

        $examples = [];

        foreach ($union[1] as $position => $shape) {

            $this->held = [$union[0], $shape];

            $value = $this->value($pointer, []);

            if ($value instanceof MissingValue) {
                return $value;
            }

            $examples[$this->unions->name($shape, $position, $examples)] = $value;
        }

        return $examples;
    }

    /**
     * Take the first candidate the node accepts, else fall back.
     *
     * @param  string  $pointer
     * @param  list<string>  $stack
     * @return mixed
     */
    private function value(string $pointer, array $stack): mixed
    {
        $node     = $this->schemas->node($pointer);
        $node     = is_array($node) ? $node : [];
        $rejected = [];

        foreach ($this->candidates($pointer, $node, $stack) as $candidate) {

            if (!$candidate instanceof MissingValue && $this->schemas->mismatch($candidate, $pointer) === null) {
                return $candidate;
            }

            $rejected[] = $candidate;
        }

        return $this->fallback($pointer, $node, $rejected);
    }

    /**
     * Fall back to the first rejected composite, or any rejected value of a
     * union, for the final validation to report, else to the first node found
     * without a value.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @param  list<mixed>  $rejected
     * @return mixed
     */
    private function fallback(string $pointer, array $node, array $rejected): mixed
    {
        $union   = array_intersect_key($node, array_flip(self::UNION_KEYWORDS)) !== [];
        $missing = null;

        foreach ($rejected as $candidate) {

            if ($candidate instanceof MissingValue) {
                $missing ??= $candidate;

                continue;
            }

            if ($union || is_array($candidate) || $candidate instanceof \stdClass) {
                return $candidate;
            }
        }

        return $missing ?? new MissingValue($pointer);
    }

    /**
     * Yield the node's candidate values in order of precedence.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $stack
     * @return iterable<mixed>
     */
    private function candidates(string $pointer, array $node, array $stack): iterable
    {
        if ($this->held !== null && $this->held[0] === $pointer) {

            $shape = $this->value($this->held[1], $stack);

            yield $shape;

            if (!($shape instanceof MissingValue && $shape->undocumented) && $this->isObject($this->schemas->conjuncts($pointer, $node))) {
                yield from $this->typed($pointer, $node, $stack);
            }

            return;
        }

        yield from $this->literals->listed($node);

        if (($node['x-undocumented'] ?? false) === true) {
            yield new MissingValue($pointer, true);

            return;
        }

        $target = $this->schemas->target($pointer);

        if ($target !== $pointer) {
            yield in_array($target, $stack, true) ? new MissingValue($target) : $this->value($target, [...$stack, $target]);
        }

        yield from $this->alternatives($pointer, $node, $stack);
        yield from $this->typed($pointer, $node, $stack);
    }

    /**
     * Yield the value of each union alternative that constrains a value, null
     * last.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $stack
     * @return iterable<mixed>
     */
    private function alternatives(string $pointer, array $node, array $stack): iterable
    {
        foreach ($this->unions->valueAlternatives($pointer, $node) as $alternative) {
            yield $this->value($alternative, $stack);
        }

        if (!in_array(self::NULL_SCHEMA, [...(array) ($node['oneOf'] ?? []), ...(array) ($node['anyOf'] ?? [])], true)) {
            return;
        }

        yield null;
    }

    /**
     * Yield the values the node's types admit, its conjuncts' candidates first
     * when it is not an object.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $stack
     * @return iterable<mixed>
     */
    private function typed(string $pointer, array $node, array $stack): iterable
    {
        $parts  = $this->schemas->conjuncts($pointer, $node);
        $object = $this->isObject($parts);

        foreach ($object ? [] : array_slice($parts, 1, null, true) as $conjunct => $part) {
            yield from $this->candidates($conjunct, $part, $stack);
        }

        foreach ($this->types($node, $object) as $type) {
            yield from match ($type) {
                'object' => $this->objects($node, $parts, $stack),
                'array'  => [$this->list($pointer, $node, $stack)],
                default  => [$this->literals->scalar($type, $node)],
            };
        }
    }

    /**
     * List the node's types, null last, inferring them when it names none.
     *
     * @param  array<array-key, mixed>  $node
     * @param  bool  $object
     * @return list<string>
     */
    private function types(array $node, bool $object): array
    {
        $types = $this->names($node, 'type');

        if ($types === []) {
            return match (true) {
                $object                          => ['object'],
                array_key_exists('items', $node) => ['array'],
                default                          => ['string'],
            };
        }

        $values = array_values(array_diff($types, ['null']));

        return in_array('null', $types, true) ? [...$values, 'null'] : $values;
    }

    /**
     * Yield an object from each set of properties the scope prefers, merged
     * across the node's conjuncts and the alternative each of their unions
     * takes, until a required property has no value.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, array<array-key, mixed>>  $parts
     * @param  list<string>  $stack
     * @return iterable<\SineMacula\ApiToolkit\OpenApi\Examples\MissingValue|\stdClass>
     */
    private function objects(array $node, array $parts, array $stack): iterable
    {
        $properties = [];
        $required   = [];

        foreach ($this->withAlternatives($parts) as $pointer => $part) {

            foreach (array_keys(is_array($part['properties'] ?? null) ? $part['properties'] : []) as $name) {
                $properties[$name] ??= SchemaDocument::append(SchemaDocument::append($pointer, 'properties'), $name);
            }

            $required = [...$required, ...$this->names($part, 'required')];
        }

        $names    = array_map(strval(...), array_keys($properties));
        $required = array_values(array_intersect($names, $required));
        $resolved = [];

        foreach ($this->propertySets($node, $names, $required) as $set) {

            $object = $this->object($set, $properties, $required, $resolved, $stack);

            yield $object;

            if ($object instanceof MissingValue) {
                return;
            }
        }
    }

    /**
     * Add to an object's parts the alternative each of their unions takes: the
     * held shape, else the first that constrains a value.
     *
     * @param  array<string, array<array-key, mixed>>  $parts
     * @return array<string, array<array-key, mixed>>
     */
    private function withAlternatives(array $parts): array
    {
        foreach ($parts as $pointer => $part) {

            $alternative = $this->held !== null && $this->held[0] === $pointer ? $this->held[1] : $this->unions->valueAlternatives($pointer, $part)[0] ?? null;

            if ($alternative === null) {
                continue;
            }

            $alternative = $this->schemas->target($alternative);
            $schema      = $this->schemas->node($alternative);

            if (!is_array($schema)) {
                continue;
            }

            $parts = $this->schemas->conjuncts($alternative, $schema, $parts);
        }

        return $parts;
    }

    /**
     * List the sets of property names an object candidate is built from, in
     * order of preference.
     *
     * A request sends every property, else the required ones first up to the
     * node's maximum, else the required ones alone; a response holds the
     * required and default ones, else the required ones alone.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $names
     * @param  list<string>  $required
     * @return list<list<string>>
     */
    private function propertySets(array $node, array $names, array $required): array
    {
        if ($this->scope === ExampleScope::RESPONSE) {
            return [array_values(array_intersect($names, [...$required, ...$this->names($node, 'x-default-fields')])), $required];
        }

        $maximum = $node['maxProperties'] ?? null;
        $capped  = is_int($maximum) ? [array_values(array_intersect($names, array_slice([...$required, ...array_diff($names, $required)], 0, $maximum)))] : [];

        return [$names, ...$capped, $required];
    }

    /**
     * Build an object from the named properties that have a value, or report
     * the required one that has none.
     *
     * @param  list<string>  $names
     * @param  array<array-key, string>  $properties
     * @param  list<string>  $required
     * @param  array<array-key, mixed>  $resolved
     * @param  list<string>  $stack
     * @return \SineMacula\ApiToolkit\OpenApi\Examples\MissingValue|\stdClass
     */
    private function object(array $names, array $properties, array $required, array &$resolved, array $stack): MissingValue|\stdClass
    {
        $object = new \stdClass;

        foreach ($this->propertyValues($names, $properties, $required, $resolved, $stack) as $name => $value) {

            if (!$value instanceof MissingValue) {
                $object->{$name} = $value;

                continue;
            }

            if (in_array((string) $name, $required, true)) {
                return $value;
            }
        }

        return $object;
    }

    /**
     * Resolve the value of each named property, in order, a confirmation
     * sharing one value with the field it confirms.
     *
     * @param  list<string>  $names
     * @param  array<array-key, string>  $properties
     * @param  list<string>  $required
     * @param  array<array-key, mixed>  $resolved
     * @param  list<string>  $stack
     * @return array<array-key, mixed>
     */
    private function propertyValues(array $names, array $properties, array $required, array &$resolved, array $stack): array
    {
        $values        = [];
        $confirmations = [];

        foreach ($names as $name) {

            $schema = $this->schemas->node($properties[$name]);

            if ($schema === false) {
                continue;
            }

            $confirmations[$name] = is_array($schema) ? $schema[PresenceConstraintBuilder::CONFIRMS_KEY] ?? null : null;

            if (!array_key_exists($name, $resolved)) {
                $resolved[$name] = $this->propertyValue($properties[$name], in_array($name, $required, true), $stack);
            }

            $values[$name] = $resolved[$name];
        }

        return $this->withConfirmations($values, array_filter($confirmations, is_string(...)), $properties);
    }

    /**
     * Resolve a property's value, leaving out an optional one its schema
     * rejects.
     *
     * @param  string  $pointer
     * @param  bool  $required
     * @param  list<string>  $stack
     * @return mixed
     */
    private function propertyValue(string $pointer, bool $required, array $stack): mixed
    {
        $value = $this->value($pointer, $stack);

        if ($required || $value instanceof MissingValue || $this->schemas->mismatch($value, $pointer) === null) {
            return $value;
        }

        return new MissingValue($pointer);
    }

    /**
     * Give each confirmation the value of the field it confirms, where that
     * field has one, first giving the field a confirmation's own value when
     * only that value suits both.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>  $confirmations
     * @param  array<array-key, string>  $properties
     * @return array<array-key, mixed>
     */
    private function withConfirmations(array $values, array $confirmations, array $properties): array
    {
        $confirmations = array_filter($confirmations, static fn (string $original): bool => array_key_exists($original, $values) && !$values[$original] instanceof MissingValue);

        foreach ($confirmations as $name => $original) {

            $own  = $values[$name];
            $kept = $own instanceof MissingValue
                || $this->schemas->mismatch($values[$original], $properties[$name]) === null
                || $this->schemas->mismatch($own, $properties[$original]) !== null;

            if ($kept) {
                continue;
            }

            $values[$original] = $own;
        }

        foreach ($confirmations as $name => $original) {
            $values[$name] = $values[$original];
        }

        return $values;
    }

    /**
     * Build a list of the fewest items the node allows, at least one, each the
     * value of its items.
     *
     * A list that may be empty is left empty when its item has no value, unless
     * the item is undocumented. Unique items are each a different candidate.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $stack
     * @return mixed
     */
    private function list(string $pointer, array $node, array $stack): mixed
    {
        $minimum = is_int($node['minItems'] ?? null) ? $node['minItems'] : 0;
        $count   = is_int($node['maxItems'] ?? null) ? min(max($minimum, 1), $node['maxItems']) : max($minimum, 1);

        if ($count > 1 && ($node['uniqueItems'] ?? false) === true) {
            return $this->distinctItems($pointer, $count, $stack);
        }

        $item = $count > 0 && array_key_exists('items', $node) ? $this->value(SchemaDocument::append($pointer, 'items'), $stack) : 'string';

        if ($item instanceof MissingValue) {
            return $minimum === 0 && !$item->undocumented ? [] : $item;
        }

        return array_fill(0, $count, $item);
    }

    /**
     * Build a list of distinct items its items accept, or report the list when
     * too few differ.
     *
     * @param  string  $pointer
     * @param  int  $count
     * @param  list<string>  $stack
     * @return list<mixed>|\SineMacula\ApiToolkit\OpenApi\Examples\MissingValue
     */
    private function distinctItems(string $pointer, int $count, array $stack): array|MissingValue
    {
        $items    = SchemaDocument::append($pointer, 'items');
        $distinct = [];

        foreach ($this->itemCandidates($items, $count, $stack) as $candidate) {

            if ($candidate instanceof MissingValue && $candidate->undocumented) {
                return $candidate;
            }

            if ($candidate instanceof MissingValue || $this->schemas->mismatch($candidate, $items) !== null) {
                continue;
            }

            $distinct[json_encode($candidate, JSON_THROW_ON_ERROR)] = $candidate;

            if (count($distinct) === $count) {
                return array_values($distinct);
            }
        }

        return new MissingValue($pointer);
    }

    /**
     * Yield the candidates of an items schema, or of the schema it references,
     * then the variants of each scalar type it or its conjuncts admit, none
     * when the walk is already inside that schema.
     *
     * @param  string  $items
     * @param  int  $count
     * @param  list<string>  $stack
     * @return iterable<mixed>
     */
    private function itemCandidates(string $items, int $count, array $stack): iterable
    {
        $source = $this->schemas->target($items);
        $schema = $this->schemas->node($source);

        if (!is_array($schema) || in_array($source, $stack, true)) {
            return [];
        }

        yield from $this->candidates($source, $schema, [...$stack, $source]);

        foreach ($this->schemas->conjuncts($source, $schema) as $part) {
            foreach ($this->types($part, false) as $type) {
                yield from $this->literals->variants($type, $part, $count);
            }
        }
    }

    /**
     * Read the names a keyword of the node lists.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $keyword
     * @return list<string>
     */
    private function names(array $node, string $keyword): array
    {
        return array_values(array_filter((array) ($node[$keyword] ?? []), is_string(...)));
    }

    /**
     * Determine whether any of a node's parts describes an object.
     *
     * @param  array<string, array<array-key, mixed>>  $parts
     * @return bool
     */
    private function isObject(array $parts): bool
    {
        return array_filter($parts, static fn (array $part): bool => array_key_exists('properties', $part) || in_array('object', (array) ($part['type'] ?? []), true)) !== [];
    }
}
