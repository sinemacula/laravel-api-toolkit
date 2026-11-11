<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Builds the presence constraints an object schema carries for the confirmed
 * and conditional presence rules of its fields.
 *
 * Only sibling references are expressed; a partial `_all` rule is dropped so it
 * never demands more than the validator.
 *
 * @phpstan-type Constraints array{
 *     properties: array<string, mixed>,
 *     required: list<string>,
 *     dependentRequired: array<string, list<string>>,
 *     conditions: array<string, array<string, mixed>>,
 * }
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class PresenceConstraintBuilder
{
    /** The rules requiring a field by the presence or absence of others */
    private const array CONDITIONAL_RULES = [
        'required_with', 'required_with_all', 'required_without', 'required_without_all',
    ];

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\FieldPresence  $fields
     */
    public function __construct(

        /** The reader of how a field's rules govern its presence. */
        private FieldPresence $fields = new FieldPresence,
    ) {}

    /**
     * Add the confirmation siblings and presence constraints to an object
     * schema.
     *
     * @param  array<string, mixed>  $object
     * @param  string  $path
     * @param  array<array-key, list<string>>  $tokens
     * @return array<string, mixed>
     */
    public function build(array $object, string $path, array $tokens): array
    {
        $prefix = $path === '' ? '' : $path . '.';

        /** @var array<array-key, mixed> $properties */
        $properties = $object['properties'] ?? [];

        /** @var list<string> $required */
        $required = $object['required'] ?? [];

        $constraints = ['properties' => [], 'required' => $required, 'dependentRequired' => [], 'conditions' => []];

        foreach ($properties as $key => $schema) {
            $constraints['properties'][$key] = $schema;

            if ($schema === false) {
                continue;
            }

            $constraints = $this->confirm($constraints, $properties, $prefix, (string) $key, $tokens[$key] ?? []);
        }

        foreach ($properties as $key => $schema) {
            $name = (string) $key;
            $own  = $tokens[$key] ?? [];

            if (in_array($name, $required, true) || $this->arguments($own, 'sometimes') !== [] || $this->fields->isExcludable($own)) {
                continue;
            }

            $constraints = $this->conditions($constraints, $prefix, $name, $own);
        }

        return $this->assemble($object, $constraints);
    }

    /**
     * Add and require the confirmation sibling each confirmed rule names.
     *
     * A sibling shared by fields of different types is left unconstrained.
     *
     * @param  Constraints  $constraints
     * @param  array<array-key, mixed>  $declared
     * @param  string  $prefix
     * @param  string  $name
     * @param  list<string>  $tokens
     * @return Constraints
     */
    private function confirm(array $constraints, array $declared, string $prefix, string $name, array $tokens): array
    {
        $excludable = $this->fields->isExcludable($tokens);

        foreach ($this->confirmationTargets($prefix, $name, $tokens) as $target) {
            if (!array_key_exists($target, $declared)) {
                $schema = $this->confirmationSchema($declared[$name]);

                $constraints['properties'][$target] = ($constraints['properties'][$target] ?? $schema) === $schema ? $schema : [];
            }

            $constraints = match (true) {
                $excludable                                     => $constraints,
                in_array($name, $constraints['required'], true) => $this->requireAlways($constraints, $target),
                default                                         => $this->requireWith($constraints, $name, $target),
            };
        }

        return $constraints;
    }

    /**
     * Resolve the confirmation field each confirmed rule of a field names.
     *
     * @param  string  $prefix
     * @param  string  $name
     * @param  list<string>  $tokens
     * @return array<int, string>
     */
    private function confirmationTargets(string $prefix, string $name, array $tokens): array
    {
        $targets = [];

        foreach ($this->arguments($tokens, 'confirmed') as $arguments) {
            $targets[] = $arguments[0] === '' ? $name . '_confirmation' : $this->sibling($prefix, $arguments[0]);
        }

        return array_filter($targets, static fn (?string $target): bool => $target !== null);
    }

    /**
     * Add the constraints each conditional presence rule of a field expresses.
     *
     * @param  Constraints  $constraints
     * @param  string  $prefix
     * @param  string  $name
     * @param  list<string>  $tokens
     * @return Constraints
     */
    private function conditions(array $constraints, string $prefix, string $name, array $tokens): array
    {
        foreach ($tokens as $token) {
            [$rule, $arguments] = $this->parse($token);

            if (!in_array($rule, self::CONDITIONAL_RULES, true)) {
                continue;
            }

            $references = $this->references($constraints['properties'], $prefix, $arguments);
            $partial    = count($references) < count(array_unique($arguments));

            if ($references === [] || ($partial && str_ends_with($rule, '_all'))) {
                continue;
            }

            $constraints = $this->condition($constraints, $rule, $name, $references);
        }

        return $constraints;
    }

    /**
     * Add the constraint one conditional presence rule expresses.
     *
     * @param  Constraints  $constraints
     * @param  string  $rule
     * @param  string  $name
     * @param  non-empty-list<string>  $references
     * @return Constraints
     */
    private function condition(array $constraints, string $rule, string $name, array $references): array
    {
        $alone = array_map(static fn (string $reference): array => [$reference], $references);

        return match (true) {
            $rule === 'required_with',
            $rule === 'required_with_all' && count($references) === 1 => $this->requireWithAny($constraints, $references, $name),
            $rule === 'required_with_all'                             => $this->addCondition($constraints, ['if' => $references, 'then' => [$name]]),
            $rule === 'required_without'                              => $this->addCondition($constraints, [[$name], $references]),
            default                                                   => $this->addCondition($constraints, [[$name], ...$alone]),
        };
    }

    /**
     * Require a field whenever any of the given fields is present.
     *
     * @param  Constraints  $constraints
     * @param  list<string>  $triggers
     * @param  string  $name
     * @return Constraints
     */
    private function requireWithAny(array $constraints, array $triggers, string $name): array
    {
        foreach ($triggers as $trigger) {
            $constraints = $this->requireWith($constraints, $trigger, $name);
        }

        return $constraints;
    }

    /**
     * Require a field whenever the given trigger field is present.
     *
     * @param  Constraints  $constraints
     * @param  string  $trigger
     * @param  string  $name
     * @return Constraints
     */
    private function requireWith(array $constraints, string $trigger, string $name): array
    {
        if (!in_array($name, $constraints['dependentRequired'][$trigger] ?? [], true)) {
            $constraints['dependentRequired'][$trigger][] = $name;
        }

        return $constraints;
    }

    /**
     * Require a field unconditionally.
     *
     * @param  Constraints  $constraints
     * @param  string  $name
     * @return Constraints
     */
    private function requireAlways(array $constraints, string $name): array
    {
        if (!in_array($name, $constraints['required'], true)) {
            $constraints['required'][] = $name;
        }

        return $constraints;
    }

    /**
     * Add an anyOf or if/then condition, keyed so each is kept once.
     *
     * @param  Constraints  $constraints
     * @param  array<array-key, list<string>>  $condition
     * @return Constraints
     */
    private function addCondition(array $constraints, array $condition): array
    {
        $key = array_map(static function (array $names): array {
            sort($names);

            return $names;
        }, $condition);

        if (array_is_list($key)) {
            sort($key);
        }

        $required = array_map(static fn (array $names): array => ['required' => $names], $condition);

        $constraints['conditions'][json_encode($key, JSON_THROW_ON_ERROR)] ??= array_is_list($condition) ? ['anyOf' => $required] : $required;

        return $constraints;
    }

    /**
     * Fold the collected constraints into the object schema.
     *
     * A lone non-clashing condition is inlined; any others combine under allOf.
     *
     * @param  array<string, mixed>  $object
     * @param  Constraints  $constraints
     * @return array<string, mixed>
     */
    private function assemble(array $object, array $constraints): array
    {
        $object['properties'] = $constraints['properties'];

        if ($constraints['required'] !== []) {
            $object['required'] = $constraints['required'];
        }

        if ($constraints['dependentRequired'] !== []) {
            $object['dependentRequired'] = $constraints['dependentRequired'];
        }

        $conditions = array_values($constraints['conditions']);

        if (count($conditions) === 1 && array_intersect_key($conditions[0], $object) === []) {
            return $object + $conditions[0];
        }

        if ($conditions !== []) {
            $object['allOf'] = $conditions;
        }

        return $object;
    }

    /**
     * Resolve the distinct field references of a rule that name a sibling.
     *
     * @param  array<string, mixed>  $properties
     * @param  string  $prefix
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function references(array $properties, string $prefix, array $arguments): array
    {
        $references = [];

        foreach ($arguments as $argument) {
            $sibling = $this->sibling($prefix, $argument);

            if ($sibling === null || !array_key_exists($sibling, $properties) || in_array($sibling, $references, true)) {
                continue;
            }

            $references[] = $sibling;
        }

        return $references;
    }

    /**
     * Resolve a field path to the name of the sibling property it denotes.
     *
     * @param  string  $prefix
     * @param  string  $path
     * @return string|null
     */
    private function sibling(string $prefix, string $path): ?string
    {
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $name = substr($path, strlen($prefix));

        return $name === '' || str_contains($name, '.') ? null : $name;
    }

    /**
     * Read the arguments of every token carrying the given rule.
     *
     * @param  list<string>  $tokens
     * @param  string  $rule
     * @return list<non-empty-list<string>>
     */
    private function arguments(array $tokens, string $rule): array
    {
        $arguments = [];

        foreach ($tokens as $token) {
            [$name, $list] = $this->parse($token);

            if ($name !== $rule) {
                continue;
            }

            $arguments[] = $list;
        }

        return $arguments;
    }

    /**
     * Split a rule token into its rule name and arguments as the validator
     * does.
     *
     * @param  string  $token
     * @return array{0: string, 1: non-empty-list<string>}
     */
    private function parse(string $token): array
    {
        $parts     = explode(':', $token, 2);
        $arguments = str_getcsv($parts[1] ?? '', ',', '"', '\\');

        return [$parts[0], array_map(static fn (?string $argument): string => (string) $argument, $arguments)];
    }

    /**
     * Build the schema for a confirmation sibling from the confirmed field's
     * types.
     *
     * @param  mixed  $schema
     * @return array{type: non-empty-list<string>|string}
     */
    private function confirmationSchema(mixed $schema): array
    {
        $type  = is_array($schema) ? $schema['type'] ?? null : null;
        $types = array_values(array_filter((array) $type, static fn (mixed $name): bool => is_string($name) && $name !== 'null'));

        return match (count($types)) {
            0       => ['type' => 'string'],
            1       => ['type' => $types[0]],
            default => ['type' => $types],
        };
    }
}
