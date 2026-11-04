<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;

/**
 * Self-validating input base for typed service inputs.
 *
 * Concrete subclasses declare constructor-promoted readonly properties and
 * override rules() to supply standard Laravel validation rules. Call from() to
 * validate a request or raw array and produce a typed, immutable instance.
 * Direct named-argument construction is also supported for tests and queue
 * deserialisers that already hold validated data.
 *
 * Each property maps to the body key whose first segment camelCases to its
 * name, so a client_name rule hydrates $clientName and toArray() returns it as
 * client_name. A property named exactly after a rule key takes precedence, and
 * among keys that camelCase to the same name the first declared wins.
 *
 * The toArray() property list and the key map are each resolved once per class
 * and cached for the process. They derive only from the class definition and
 * its static rules() keys, so they never go stale; toArray() calls rules() once
 * per class, which the DefinesInputSchema contract already requires to be safe.
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
     * Validate the source and return a hydrated instance.
     *
     * Normalises the source to an array, validates it with Laravel's validator
     * using the rules returned by rules() (throwing ValidationException on
     * failure), then constructs the concrete class with named arguments drawn
     * from the validated data subset. Each promoted parameter reads the body
     * key mapped to its name, so client_name hydrates $clientName; only the
     * first segment of a dotted rule maps, leaving its nested data untouched.
     * Backed enum parameters are cast from their string representation
     * automatically.
     *
     * @param  array<string, mixed>|\Illuminate\Http\Request  $source
     * @return static
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function from(array|Request $source): static
    {
        $data      = $source instanceof Request ? $source->all() : $source;
        $validated = Validator::make($data, static::rules())->validate();

        $constructor = (new \ReflectionClass(static::class))->getConstructor();
        $keys        = self::bodyKeys(static::class);
        $namedArgs   = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isPromoted()) {
                    continue;
                }

                $name = $parameter->getName();
                $key  = $keys[$name] ?? $name;

                if (!array_key_exists($key, $validated)) {
                    continue;
                }

                $namedArgs[$name] = self::castValue($parameter, $validated[$key], $key);
            }
        }

        $className = static::class;

        return new $className(...$namedArgs);
    }

    /**
     * Return the input as an associative array.
     *
     * Reflects over the instance's public promoted properties and returns a map
     * keyed in the body's form, so $clientName is returned as client_name; a
     * property no rule key maps keeps its own name. Concrete subclasses need
     * not implement toArray() unless they require custom serialisation.
     *
     * The returned map is carried on the service lifecycle events as
     * inputSummary and reaches event listeners verbatim. Mark a promoted
     * constructor parameter with #[\SensitiveParameter] to leave it out of the
     * map; the value stays readable on the instance.
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
     * Concrete subclasses override this method to declare per-field rules using
     * standard Laravel rule syntax. Cross-field constraints such as confirmed
     * or after:other_field are also expressed here.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [];
    }

    /**
     * Return the body key each property of a class maps to.
     *
     * Resolved once per class from the first segments of its rules() keys: each
     * segment maps from its own name and from its camelCase form, the exact
     * name winning and otherwise the first declared key.
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
        $segments = array_map(static fn (string $key): string => Str::before($key, '.'), array_keys($class::rules()));
        $camel    = [];

        foreach ($segments as $segment) {
            $camel[Str::camel($segment)] ??= $segment;
        }

        return array_combine($segments, $segments) + $camel;
    }

    /**
     * Return the names of the properties that toArray() exposes for a class.
     *
     * Resolved once per class: the public promoted properties, minus any whose
     * constructor parameter carries #[\SensitiveParameter].
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
     * The attribute only targets parameters, so it is read from the
     * constructors that can promote the property: the declaring class's own and
     * those of its traits, which still promote when aliased or shadowed.
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
     * Cast a validated value to the parameter's declared PHP type.
     *
     * Only backed enum parameters require casting from string; all other types
     * are returned as-is because Laravel's validator already yields the correct
     * scalar representation. When a subclass omits Rule::enum, an invalid
     * string still reaches this cast; tryFrom degrades that to a validation
     * failure so the caller sees a 422 rather than an unhandled ValueError,
     * keyed by the body key the value was read from.
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
        $type = $parameter->getType();

        if ($value === null || !$type instanceof \ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if (!is_string($value) || !class_exists($typeName) || !is_a($typeName, \BackedEnum::class, true)) {
            return $value;
        }

        /** @var class-string<\BackedEnum> $typeName */
        return $typeName::tryFrom($value) ?? self::failEnumCast($key);
    }

    /**
     * Fail an unmatched enum value as a validation error.
     *
     * Mirrors Laravel's enum-rule message, naming the body key in the
     * validator's display form, so a missing Rule::enum degrades to the same
     * 422 contract instead of a fatal ValueError from ::from().
     *
     * @param  string  $key
     * @return never
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private static function failEnumCast(string $key): never
    {
        $attribute = str_replace('_', ' ', Str::snake($key));

        throw ValidationException::withMessages([$key => ["The selected {$attribute} is invalid."]]);
    }
}
