<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;

/**
 * Self-validating input base for typed service inputs.
 *
 * Concrete subclasses declare constructor-promoted readonly properties
 * (optionally annotated with validation attributes) and call from() to
 * validate a request or raw array then produce a typed, immutable instance.
 * Direct named-argument construction is also supported for tests and queue
 * deserialisers that already hold validated data.
 *
 * The rules() method is declared protected static so the static factory
 * from() can call static::rules() without constructing the object first,
 * allowing concrete subclasses to contribute cross-field constraints via a
 * plain override.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class InputData implements ServiceInput
{
    /**
     * Validate the source and return a hydrated instance.
     *
     * Normalises the source to an array, compiles rules from the concrete
     * class's promoted-property attributes plus any static rules() overrides,
     * validates via Laravel's validator (throwing ValidationException on
     * failure), then constructs the concrete class with named arguments drawn
     * from the validated data subset.
     *
     * @param  array<string, mixed>|\Illuminate\Http\Request  $source
     * @return static
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function from(array|Request $source): static
    {
        $data      = $source instanceof Request ? $source->all() : $source;
        $rules     = (new RuleCompiler)->compile(static::class, static::rules());
        $validated = Validator::make($data, $rules)->validate();

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

                $namedArgs[$name] = $validated[$name];
            }
        }

        $className = static::class;

        return new $className(...$namedArgs);
    }

    /**
     * Return the input as an associative array.
     *
     * Reflects over the instance's public promoted properties and returns
     * a name-to-value map. Concrete subclasses need not implement toArray()
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
     * Return additional validation rule overrides for cross-field constraints.
     *
     * Concrete subclasses override this method to contribute rules that span
     * multiple fields (e.g., confirmed, after:other_field) or to replace
     * attribute-derived rules entirely. Override keys take precedence over the
     * compiled attribute rules.
     *
     * @return array<string, array<int, mixed>>
     */
    protected static function rules(): array
    {
        return [];
    }
}
