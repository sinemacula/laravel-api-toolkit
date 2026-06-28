<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class InputData implements ServiceInput
{
    /**
     * Validate the source and return a hydrated instance.
     *
     * Normalises the source to an array, validates it with Laravel's validator
     * using the rules returned by rules() (throwing ValidationException on
     * failure), then constructs the concrete class with named arguments drawn
     * from the validated data subset. Backed enum parameters are cast from
     * their string representation automatically.
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
        $namedArgs   = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isPromoted()) {
                    continue;
                }

                $name = $parameter->getName();

                if (!array_key_exists($name, $validated)) {
                    continue;
                }

                $namedArgs[$name] = self::castValue($parameter, $validated[$name]);
            }
        }

        $className = static::class;

        return new $className(...$namedArgs);
    }

    /**
     * Return the input as an associative array.
     *
     * Reflects over the instance's public promoted properties and returns a
     * name-to-value map. Concrete subclasses need not implement toArray()
     * unless they require custom serialisation.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $result     = [];
        $allVars    = (array) $this;
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isPromoted()) {
                continue;
            }

            $name = $property->getName();

            if (!array_key_exists($name, $allVars)) {
                continue;
            }

            $result[$name] = $allVars[$name];
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
    public static function rules(): array
    {
        return [];
    }

    /**
     * Cast a validated value to the parameter's declared PHP type.
     *
     * Only backed enum parameters require casting from string; all other types
     * are returned as-is because Laravel's validator already yields the correct
     * scalar representation.
     *
     * @param  \ReflectionParameter  $parameter
     * @param  mixed  $value
     * @return mixed
     */
    private static function castValue(\ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();

        if ($value !== null && $type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();

            if (is_string($value) && class_exists($typeName) && is_a($typeName, \BackedEnum::class, true)) {
                /** @var class-string<\BackedEnum> $typeName */
                return $typeName::from($value);
            }
        }

        return $value;
    }
}
