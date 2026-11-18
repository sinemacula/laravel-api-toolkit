<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input;

use Illuminate\Foundation\Precognition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as ValidatorInstance;
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
    /** @var array<int|string, bool> The values the boolean, accepted and declined rules accept */
    private const array BOOLEANS = [1 => true, 'true' => true, 'yes' => true, 'on' => true, 0 => false, 'false' => false, 'no' => false, 'off' => false];

    /** @var array<class-string, list<string>> Summary property names per class */
    private static array $summaryProperties = [];

    /** @var array<class-string, array<string, string>> Body key per property name per class */
    private static array $bodyKeys = [];

    /** @var list<string>|null The properties from() hydrated; null when constructed directly */
    private ?array $hydrated = null;

    /**
     * Validate the source against rules() and return a hydrated instance.
     *
     * Enum parameters are cast from a backed value or a lowercase case name,
     * checked by the validator so an unmatched value fails with the rest. Int,
     * float and bool parameters are cast from the strings their rules accept. A
     * precognitive request validates only the fields it names.
     *
     * @param  array<string, mixed>|\Illuminate\Http\Request  $source
     * @return static
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \LogicException
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public static function from(array|Request $source): static
    {
        $data       = $source instanceof Request ? $source->all() : $source;
        $rules      = static::rules();
        $keys       = self::bodyKeys(static::class);
        $parameters = self::promotedParameters();
        $validator  = Validator::make($data, $rules, static::messages(), static::attributes());

        foreach ($parameters as $name => $parameter) {

            $key  = $keys[$name] ?? $name;
            $enum = self::enumType($parameter);

            if ($enum === null || !self::isRuledWhole($rules, $name, $key)) {
                continue;
            }

            $validator->addRules([$key => [new Enum($enum)]]);
        }

        if ($source instanceof Request) {
            self::applyPrecognition($validator, $source);
        }

        $namedArgs          = self::namedArguments($parameters, $keys, $validator->validate());
        $className          = static::class;
        $instance           = new $className(...$namedArgs);
        $instance->hydrated = array_keys($namedArgs);

        return $instance;
    }

    /**
     * Return the public promoted properties keyed by their body keys.
     *
     * An instance built by from() leaves out the properties its input omitted,
     * so a default is never mistaken for a sent value. The map reaches
     * lifecycle-event listeners verbatim; mark a parameter
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
        $names   = self::summaryProperties(static::class);

        foreach ($this->hydrated === null ? $names : array_intersect($names, $this->hydrated) as $name) {
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
     * Return the custom validation messages for this input.
     *
     * @return array<string, array<string, string>|string>
     */
    public static function messages(): array
    {
        return [];
    }

    /**
     * Return the custom attribute names for this input.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
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
     * Determine whether a rule reaches an enum property's body key whole, and
     * fail when one nests under it, since the value could then name no case.
     *
     * Keys match as the validator expands them, so * and *.name reach every
     * body key.
     *
     * @param  array<array-key, mixed>  $rules
     * @param  string  $name
     * @param  string  $key
     * @return bool
     *
     * @throws \LogicException
     */
    private static function isRuledWhole(array $rules, string $name, string $key): bool
    {
        $ruled = false;

        foreach (array_keys($rules) as $rule) {

            $rule   = (string) $rule;
            $parent = Str::before($rule, '.');
            $prefix = Str::before($parent, '*');

            // The validator only expands a wildcard below its literal prefix
            if (!Str::is($parent, $key) || ($prefix !== '' && $prefix !== $key)) {
                continue;
            }

            if ($parent !== $rule) {
                throw new \LogicException(sprintf('The rule "%s" nests under %s::$%s, an enum read from the whole value', $rule, static::class, $name));
            }

            $ruled = true;
        }

        return $ruled;
    }

    /**
     * Validate a precognitive request's named fields only, and end it with a
     * Precognition success once they pass.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private static function applyPrecognition(ValidatorInstance $validator, Request $request): void
    {
        if (!$request->isPrecognitive()) {
            return;
        }

        $validator->after(Precognition::afterValidationHook($request));
        $validator->setRules($request->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders()));
    }

    /**
     * Map each promoted parameter to its cast validated value, leaving out the
     * ones the input omitted.
     *
     * @param  array<string, \ReflectionParameter>  $parameters
     * @param  array<string, string>  $keys
     * @param  array<array-key, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws \LogicException
     */
    private static function namedArguments(array $parameters, array $keys, array $validated): array
    {
        $namedArgs = [];

        foreach ($parameters as $name => $parameter) {

            $key = $keys[$name] ?? $name;

            if (array_key_exists($key, $validated)) {
                $namedArgs[$name] = self::castValue($parameter, $validated[$key]);
            } elseif (!$parameter->isOptional()) {
                throw new \LogicException(sprintf('%s::$%s has no default, but its rules let "%s" be absent', static::class, $name, $key));
            }
        }

        return $namedArgs;
    }

    /**
     * Cast a validated value to the parameter's enum or scalar type.
     *
     * @param  \ReflectionParameter  $parameter
     * @param  mixed  $value
     * @return mixed
     */
    private static function castValue(\ReflectionParameter $parameter, mixed $value): mixed
    {
        $enum = self::enumType($parameter);

        if ($enum !== null) {
            return $value instanceof $enum ? $value : Enum::resolve($enum, $value);
        }

        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType ? self::castScalar($type->getName(), $value) : $value;
    }

    /**
     * Cast a value to an int, float or bool type only where the integer,
     * numeric, boolean, accepted or declined rule accepts it, leaving any other
     * value unchanged.
     *
     * @param  string  $type
     * @param  mixed  $value
     * @return mixed
     */
    private static function castScalar(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int'   => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool'  => is_int($value) || is_string($value) ? self::BOOLEANS[$value] ?? $value : $value,
            default => $value,
        };
    }
}
