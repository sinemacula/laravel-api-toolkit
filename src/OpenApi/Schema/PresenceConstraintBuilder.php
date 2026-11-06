<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Builds the presence constraints an object schema carries for the confirmed
 * and conditional presence rules of its fields.
 *
 * A confirmed field gains its confirmation sibling, `<field>_confirmation` or
 * the field the rule names, which is required alongside a required field and
 * required by the field's presence otherwise. A `required_with` field is
 * required by the presence of any field it names and a `required_with_all`
 * field by the presence of all of them, while a `required_without` field must
 * be sent unless every field it names is, and a `required_without_all` field
 * unless any of them is. Only a reference to a sibling property of the same
 * object is expressed: an unplaceable reference is dropped from a rule needing
 * any one of its fields, and drops a rule needing all of them, since keeping
 * part of that rule would demand fields the validator does not. A field an
 * exclude rule may drop from validation requires nothing, as the validator may
 * never run its rules. A condition stated more than once is emitted once, and
 * several combine under allOf.
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
     * Add the confirmation siblings and presence constraints to an object
     * schema whose properties and required list are already folded in.
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

            if (in_array($name, $required, true) || $this->arguments($own, 'sometimes') !== [] || $this->isExcludable($own)) {
                continue;
            }

            $constraints = $this->conditions($constraints, $prefix, $name, $own);
        }

        return $this->assemble($object, $constraints);
    }

    /**
     * Add the confirmation sibling each confirmed rule of a field names, and
     * require it alongside the field.
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
        foreach ($this->arguments($tokens, 'confirmed') as $arguments) {
            $target = $arguments[0] === '' ? $name . '_confirmation' : $this->sibling($prefix, $arguments[0]);

            if ($target === null) {
                continue;
            }

            if (!array_key_exists($target, $declared)) {
                $constraints['properties'][$target] = $this->confirmationSchema($declared[$name]);
            }

            if ($this->isExcludable($tokens)) {
                continue;
            }

            $constraints = in_array($name, $constraints['required'], true)
                ? $this->requireAlways($constraints, $target)
                : $this->requireWith($constraints, $name, $target);
        }

        return $constraints;
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
     * Add the constraint one conditional presence rule expresses over the
     * sibling fields it references.
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
     * Add a condition, either a list of alternatives one of which must be sent
     * whole or an if/then pair, keeping it once however its names are ordered.
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
     * Fold the collected constraints into the object schema, inlining a lone
     * condition its keywords do not clash with and combining any others under
     * allOf.
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
     * Resolve the field references of a rule that name a sibling property of
     * the object, in order and without repeats.
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
     * Resolve a field path to the name of the sibling property it denotes, or
     * null when it lies outside the object.
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
     * Read the comma-separated arguments of every token carrying the given
     * rule, one list per token.
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
     * Determine whether any exclude rule may drop the field from validation.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    private function isExcludable(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (str_starts_with($this->parse($token)[0], 'exclude')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a rule token into its rule name and arguments, read as the
     * validator reads them: comma-separated values that may be quoted.
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
     * Build the schema for a confirmation sibling, mirroring the base type of
     * the confirmed field.
     *
     * @param  mixed  $schema
     * @return array<string, string>
     */
    private function confirmationSchema(mixed $schema): array
    {
        $type = is_array($schema) ? $schema['type'] ?? null : null;

        return is_string($type) ? ['type' => $type] : ['type' => 'string'];
    }
}
