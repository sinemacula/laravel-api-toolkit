<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input;

use SineMacula\ApiToolkit\Services\Input\Attributes\Contracts\ValidationAttribute;

/**
 * Derives Laravel validation rules from a ServiceInput class via reflection.
 *
 * Reflects over the promoted constructor parameters of a ServiceInput subclass,
 * maps each parameter's PHP type to a base Laravel rule, layers any
 * ValidationAttribute fragments, and merges the supplied override array
 * (override wins).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RuleCompiler
{
    /**
     * Compile Laravel validation rules for the given ServiceInput class.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput>  $input
     * @param  array<string, array<int, mixed>>  $overrides
     * @return array<string, array<int, mixed>>
     */
    public function compile(string $input, array $overrides = []): array
    {
        $rules       = [];
        $constructor = (new \ReflectionClass($input))->getConstructor();

        if ($constructor === null) {
            return array_merge($rules, $overrides);
        }

        foreach ($constructor->getParameters() as $parameter) {
            if (!$parameter->isPromoted()) {
                continue;
            }

            $rules[$parameter->getName()] = $this->compileParameter($parameter);
        }

        return array_merge($rules, $overrides);
    }

    /**
     * Compile the rule fragments for a single promoted parameter.
     *
     * @param  \ReflectionParameter  $parameter
     * @return array<int, mixed>
     */
    private function compileParameter(\ReflectionParameter $parameter): array
    {
        $rules = $this->deriveTypeRules($parameter);

        foreach ($parameter->getAttributes(ValidationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $rules = array_merge($rules, $attr->newInstance()->toRules());
        }

        return array_values($rules);
    }

    /**
     * Derive base rule fragments from the parameter's PHP type.
     *
     * A nullable type (`?T`) contributes `nullable` in addition to the base
     * rule for T. Union types and untyped parameters contribute no base rule.
     *
     * @param  \ReflectionParameter  $parameter
     * @return array<int, mixed>
     */
    private function deriveTypeRules(\ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if (!($type instanceof \ReflectionNamedType)) {
            return [];
        }

        $rules    = [];
        $typeName = $type->getName();

        if ($type->allowsNull() && $typeName !== 'null') {
            $rules[] = 'nullable';
        }

        $baseRule = $this->mapTypeToRule($typeName);

        if ($baseRule !== null) {
            $rules[] = $baseRule;
        }

        return $rules;
    }

    /**
     * Map a PHP type name to a Laravel validation rule string.
     *
     * Returns null for types with no defined mapping (including unknown class
     * types that are not enums).
     *
     * @param  string  $typeName
     * @return string|null
     */
    private function mapTypeToRule(string $typeName): ?string
    {
        return match ($typeName) {
            'string' => 'string',
            'int'    => 'integer',
            'float'  => 'numeric',
            'bool'   => 'boolean',
            'array'  => 'array',
            default  => $this->resolveEnumRule($typeName),
        };
    }

    /**
     * Resolve an enum class type name to a Laravel enum rule fragment.
     *
     * Returns null when the type name does not resolve to a UnitEnum class.
     *
     * @param  string  $typeName
     * @return string|null
     */
    private function resolveEnumRule(string $typeName): ?string
    {
        if (class_exists($typeName) && is_a($typeName, \UnitEnum::class, true)) {
            return 'enum:' . $typeName;
        }

        return null;
    }
}
