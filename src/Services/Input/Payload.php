<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\Rules\Enum;

/**
 * Self-validating base for typed service inputs.
 *
 * Each promoted property maps to the rule key whose first segment camelCases to
 * its name, so a client_name rule hydrates $clientName; an exact-name key wins.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
abstract class Payload implements DefinesInputSchema, ServiceInput
{
    /** @var array<class-string, list<string>> Summary property names per class */
    private static array $summaryProperties = [];

    /** @var array<class-string, array<string, string>> Body key per property name per class */
    private static array $bodyKeys = [];

    /**
     * Validate the source against rules() and return a hydrated instance.
     *
     * Enum parameters are cast from a backed value or a lowercase case name,
     * checked by the validator so an unmatched value fails with the rest.
     *
     * @param  array<string, mixed>|\Illuminate\Http\Request  $source
     * @return static
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function from(array|Request $source): static
    {
        $data       = $source instanceof Request ? $source->all() : $source;
        $rules      = static::rules();
        $keys       = self::bodyKeys(static::class);
        $parameters = self::promotedParameters();
        $validator  = Validator::make($data, $rules);

        foreach ($parameters as $name => $parameter) {

            $key  = $keys[$name] ?? $name;
            $enum = self::enumType($parameter);

            if ($enum === null || !array_key_exists($key, $rules)) {
                continue;
            }

            $validator->addRules([$key => [new Enum($enum)]]);
        }

        $validated = $validator->validate();
        $namedArgs = [];

        foreach ($parameters as $name => $parameter) {

            $key = $keys[$name] ?? $name;

            if (!array_key_exists($key, $validated)) {
                continue;
            }

            $namedArgs[$name] = self::castValue($parameter, $validated[$key], $key);
        }

        $className = static::class;

        return new $className(...$namedArgs);
    }

    /**
     * Return the public promoted properties keyed by their body keys.
     *
     * The map reaches lifecycle-event listeners verbatim; mark a parameter
     * #[\SensitiveParameter] to leave it out.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $result  = [];
        $allVars = (array) $this;
        $keys    = self::bodyKeys(static::class);

        foreach (self::summaryProperties(static::class) as $name) {
            if (!array_key_exists($name, $allVars)) {
                continue;
            }

            $result[$keys[$name] ?? $name] = $allVars[$name];
        }

        return $result;
    }

    /**
     * Return the Laravel validation rules for this input.
     *
     * @return array<array-key, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [];
    }

    /**
     * Return the body key each property of a class maps to.
     *
     * @param  class-string<static>  $class
     * @return array<string, string>
     */
    private static function bodyKeys(string $class): array
    {
        if (!isset(self::$bodyKeys[$class])) {
            self::$bodyKeys[$class] = self::resolveBodyKeys($class);
        }

        return self::$bodyKeys[$class];
    }

    /**
     * Map each rules() first segment from its own and its camelCase name.
     *
     * @param  class-string<static>  $class
     * @return array<string, string>
     */
    private static function resolveBodyKeys(string $class): array
    {
        $segments = array_map(static fn (int|string $key): string => Str::before((string) $key, '.'), array_keys($class::rules()));
        $camel    = [];

        foreach ($segments as $segment) {
            $camel[Str::camel($segment)] ??= $segment;
        }

        return array_combine($segments, $segments) + $camel;
    }

    /**
     * Return the names of the properties that toArray() exposes for a class.
     *
     * @param  class-string<static>  $class
     * @return list<string>
     */
    private static function summaryProperties(string $class): array
    {
        if (!isset(self::$summaryProperties[$class])) {
            self::$summaryProperties[$class] = self::resolveSummaryProperties($class);
        }

        return self::$summaryProperties[$class];
    }

    /**
     * Reflect the public promoted, non-sensitive property names of a class.
     *
     * @param  class-string<static>  $class
     * @return list<string>
     */
    private static function resolveSummaryProperties(string $class): array
    {
        $names = [];

        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isPromoted() || self::isSensitive($property)) {
                continue;
            }

            $names[] = $property->getName();
        }

        return $names;
    }

    /**
     * Determine whether a promoted property's parameter is marked sensitive.
     *
     * The attribute only targets parameters, so it is read from the declaring
     * class's constructor and those of its traits.
     *
     * @param  \ReflectionProperty  $property
     * @return bool
     */
    private static function isSensitive(\ReflectionProperty $property): bool
    {
        foreach (self::promotingClasses($property->getDeclaringClass()) as $class) {
            foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $property->getName() && $parameter->getAttributes(\SensitiveParameter::class) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return a class and every trait it uses, recursively.
     *
     * @param  \ReflectionClass<object>  $class
     * @return list<\ReflectionClass<object>>
     */
    private static function promotingClasses(\ReflectionClass $class): array
    {
        $classes = [$class];

        foreach ($class->getTraits() as $trait) {
            array_push($classes, ...self::promotingClasses($trait));
        }

        return $classes;
    }

    /**
     * Return the promoted constructor parameters keyed by name.
     *
     * @return array<string, \ReflectionParameter>
     */
    private static function promotedParameters(): array
    {
        $parameters = [];

        foreach ((new \ReflectionClass(static::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isPromoted()) {
                continue;
            }

            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    /**
     * Return the enum a parameter is typed as, if any.
     *
     * @param  \ReflectionParameter  $parameter
     * @return class-string<\UnitEnum>|null
     */
    private static function enumType(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && enum_exists($type->getName()) ? $type->getName() : null;
    }

    /**
     * Cast a validated value to the parameter's enum type, if it has one.
     *
     * A key that rules() names only through nested keys never ran the enum
     * rule, so a value that names no case is validated on its own here.
     *
     * @param  \ReflectionParameter  $parameter
     * @param  mixed  $value
     * @param  string  $key
     * @return mixed
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private static function castValue(\ReflectionParameter $parameter, mixed $value, string $key): mixed
    {
        $enum = self::enumType($parameter);

        if ($enum === null || $value instanceof $enum) {
            return $value;
        }

        return Enum::resolve($enum, $value) ?? Validator::make([$key => $value], [$key => [new Enum($enum)]])->validate()[$key];
    }
}
