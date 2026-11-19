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
    /** The keywords an alternative carrying only presence rules is made of */
    private const array PRESENCE_KEYWORDS = ['required', 'dependentRequired', 'minProperties', 'maxProperties'];

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
            yield $this->value($this->held[1], $stack);

            return;
        }

        yield from $this->literals->listed($node);

        if (($node['x-undocumented'] ?? false) === true) {
            yield new MissingValue($pointer, true);

            return;
        }

        if (is_string($node['$ref'] ?? null)) {
            yield $this->referenced(substr($node['$ref'], 1), $stack);
        }

        yield from $this->alternatives($pointer, $node, $stack);
        yield from $this->typed($pointer, $node, $stack);
    }

    /**
     * Resolve the value of a referenced node, which has none when the walk is
     * already inside it.
     *
     * @param  string  $target
     * @param  list<string>  $stack
     * @return mixed
     */
    private function referenced(string $target, array $stack): mixed
    {
        if (in_array($target, $stack, true)) {
            return new MissingValue($target);
        }

        return $this->value($target, [...$stack, $target]);
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
        foreach (self::UNION_KEYWORDS as $keyword) {
            foreach (is_array($node[$keyword] ?? null) ? $node[$keyword] : [] as $index => $alternative) {

                if ($alternative === self::NULL_SCHEMA || $this->isPresenceOnly($alternative)) {
                    continue;
                }

                yield $this->value(SchemaDocument::append(SchemaDocument::append($pointer, $keyword), $index), $stack);
            }
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
        $parts  = $this->parts($pointer, $node, []);
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
     * Collect the node and the conjuncts its allOf names, a referencing one as
     * its target, keyed by pointer.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $node
     * @param  array<string, array<array-key, mixed>>  $parts
     * @return array<string, array<array-key, mixed>>
     */
    private function parts(string $pointer, array $node, array $parts): array
    {
        $parts[$pointer] = $node;

        foreach (array_keys(is_array($node['allOf'] ?? null) ? $node['allOf'] : []) as $index) {

            $conjunct  = SchemaDocument::append(SchemaDocument::append($pointer, 'allOf'), $index);
            $reference = $this->schemas->node(SchemaDocument::append($conjunct, '$ref'));
            $conjunct  = is_string($reference) ? substr($reference, 1) : $conjunct;
            $schema    = $this->schemas->node($conjunct);

            if (!is_array($schema) || array_key_exists($conjunct, $parts)) {
                continue;
            }

            $parts = $this->parts($conjunct, $schema, $parts);
        }

        return $parts;
    }

    /**
     * Determine whether a node or one of its conjuncts describes an object.
     *
     * @param  array<string, array<array-key, mixed>>  $parts
     * @return bool
     */
    private function isObject(array $parts): bool
    {
        foreach ($parts as $part) {
            if (array_key_exists('properties', $part) || in_array('object', (array) ($part['type'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Yield an object from each set of properties the scope prefers, merged
     * across the node's conjuncts, until a required property has no value.
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

        foreach ($parts as $pointer => $part) {

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

        foreach ($this->propertyValues($names, $properties, $resolved, $stack) as $name => $value) {

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
     * Resolve the value of each named property, in order, a confirmation taking
     * the value of the field it confirms.
     *
     * @param  list<string>  $names
     * @param  array<array-key, string>  $properties
     * @param  array<array-key, mixed>  $resolved
     * @param  list<string>  $stack
     * @return array<array-key, mixed>
     */
    private function propertyValues(array $names, array $properties, array &$resolved, array $stack): array
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
                $resolved[$name] = $this->value($properties[$name], $stack);
            }

            $values[$name] = $resolved[$name];
        }

        return $this->withConfirmations($values, array_filter($confirmations, is_string(...)));
    }

    /**
     * Set each confirmation to the value of the field it confirms, where that
     * field has one.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>  $confirmations
     * @return array<array-key, mixed>
     */
    private function withConfirmations(array $values, array $confirmations): array
    {
        foreach ($confirmations as $name => $original) {

            if (!array_key_exists($original, $values) || $values[$original] instanceof MissingValue) {
                continue;
            }

            $values[$name] = $values[$original];
        }

        return $values;
    }

    /**
     * Build a list of the fewest items the node allows, at least one, each the
     * value of its items.
     *
     * An item without a value leaves a list that may be empty empty, unless it
     * is undocumented.
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
        $item    = $count > 0 && array_key_exists('items', $node) ? $this->value(SchemaDocument::append($pointer, 'items'), $stack) : 'string';

        if ($item instanceof MissingValue) {
            return $minimum === 0 && !$item->undocumented ? [] : $item;
        }

        return array_fill(0, $count, $item);
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
