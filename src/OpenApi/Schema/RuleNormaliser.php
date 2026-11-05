<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Password;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;

/**
 * Flattens a Laravel rule definition into a list of string tokens.
 *
 * A field's rules may be a pipe-delimited string, an array of tokens, or a
 * mixture of tokens and rule objects. This normaliser reduces every form to a
 * single flat list of string tokens so the field-schema builder has one shape
 * to interpret. Only a whole-string definition is split on pipes: an array
 * element is one rule, as the validator reads it, so a pattern or value
 * carrying a pipe survives intact. Rule objects are expanded best-effort: an
 * Enum or PureEnum contributes an `enum-class:` token naming the enum class so
 * the builder can reference its named component, either narrowed by only() or
 * except() contributes an `enum-values:` token listing exactly what it accepts,
 * an In contributes the same `in:` token its string form renders, and a
 * Password contributes `string` plus its minimum length; any other object,
 * NotIn included, is dropped, keeping the field while discarding the constraint
 * it could not translate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RuleNormaliser
{
    /**
     * Normalise a rule definition into a flat list of string tokens.
     *
     * @param  mixed  $definition
     * @return list<string>
     */
    public function normalise(mixed $definition): array
    {
        return match (true) {
            is_string($definition) => $this->splitPipes($definition),
            is_array($definition)  => $this->normaliseArray($definition),
            is_object($definition) => $this->expandObject($definition),
            default                => [],
        };
    }

    /**
     * Normalise an array rule definition, keeping each non-empty string element
     * whole as one rule and expanding each rule object in turn.
     *
     * @param  array<array-key, mixed>  $definition
     * @return list<string>
     */
    private function normaliseArray(array $definition): array
    {
        $tokens = [];

        foreach ($definition as $element) {
            if (is_string($element) && $element !== '') {
                $tokens[] = $element;
            } elseif (is_object($element)) {
                $tokens = array_merge($tokens, $this->expandObject($element));
            }
        }

        return $tokens;
    }

    /**
     * Split a pipe-delimited rule string into its non-empty tokens.
     *
     * @param  string  $rules
     * @return list<string>
     */
    private function splitPipes(string $rules): array
    {
        return array_values(array_filter(explode('|', $rules), static fn (string $token): bool => $token !== ''));
    }

    /**
     * Expand a rule object into the tokens it contributes, or nothing when the
     * object cannot be translated.
     *
     * @param  object  $rule
     * @return list<string>
     */
    private function expandObject(object $rule): array
    {
        return match (true) {
            $rule instanceof Enum     => $this->enumTokens($rule),
            $rule instanceof In       => [(string) $rule],
            $rule instanceof PureEnum => $this->pureEnumTokens($rule),
            $rule instanceof Password => $this->passwordTokens($rule),
            default                   => [],
        };
    }

    /**
     * Expand an Enum rule into an `enum-class:` token naming the enum class, or
     * into the values it accepts when only() or except() narrows it, or nothing
     * when the class cannot be read or is not an enum.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @return list<string>
     */
    private function enumTokens(Enum $rule): array
    {
        $type = $this->enumType($rule);

        if ($type === null) {
            return [];
        }

        return $this->narrowedEnumTokens($rule, $type) ?? ['enum-class:' . $type];
    }

    /**
     * Resolve the enum class an Enum rule validates against, or null when the
     * class cannot be read or is not an enum.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @return class-string<\UnitEnum>|null
     *
     * @SuppressWarnings("php:S3011")
     */
    private function enumType(Enum $rule): ?string
    {
        try {
            $type = (new \ReflectionProperty($rule, 'type'))->getValue($rule);
        } catch (\Throwable) {
            return null;
        }

        return is_string($type) && enum_exists($type) ? $type : null;
    }

    /**
     * Expand a backed Enum rule narrowed by only() or except() into its backing
     * type token and an exact list of the values it accepts, since the enum's
     * component lists every case, or null when the rule admits every case, its
     * enum is not backed, or its narrowing cannot be read.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @param  class-string<\UnitEnum>  $type
     * @return list<string>|null
     */
    private function narrowedEnumTokens(Enum $rule, string $type): ?array
    {
        $accepted = is_subclass_of($type, \BackedEnum::class) ? $this->acceptedCases($rule, $type) : null;

        if ($accepted === null || count($accepted) === count($type::cases())) {
            return null;
        }

        return [$this->backingTypeToken($type), (new EnumValuesToken)->token($accepted)];
    }

    /**
     * Resolve the cases an Enum rule accepts, applying only() in preference to
     * except() as the rule does, or null when neither list can be read.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @param  class-string<\BackedEnum>  $type
     * @return list<\BackedEnum>|null
     *
     * @SuppressWarnings("php:S3011")
     */
    private function acceptedCases(Enum $rule, string $type): ?array
    {
        try {
            $only   = (new \ReflectionProperty($rule, 'only'))->getValue($rule);
            $except = (new \ReflectionProperty($rule, 'except'))->getValue($rule);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($only) || !is_array($except)) {
            return null;
        }

        return array_values(array_filter(
            $type::cases(),
            static fn (\BackedEnum $case): bool => $only !== [] ? in_array($case, $only, true) : !in_array($case, $except, true),
        ));
    }

    /**
     * Resolve the type token for a backed enum's values: integer for an
     * int-backed enum, string otherwise.
     *
     * @param  class-string<\BackedEnum>  $type
     * @return string
     */
    private function backingTypeToken(string $type): string
    {
        return (string) (new \ReflectionEnum($type))->getBackingType() === 'int' ? 'integer' : 'string';
    }

    /**
     * Expand a PureEnum rule into an `enum-class:` token naming the enum, or,
     * when only() or except() narrows it, into a string token and an exact list
     * of the names it accepts, since the enum's component lists every case.
     *
     * @param  \SineMacula\ApiToolkit\Validation\Rules\PureEnum  $rule
     * @return list<string>
     */
    private function pureEnumTokens(PureEnum $rule): array
    {
        $names = $rule->names();

        return count($names) === count($rule->enum::cases())
            ? ['enum-class:' . $rule->enum]
            : ['string', (new EnumValuesToken)->token($names)];
    }

    /**
     * Expand a Password rule into a string token plus its minimum length when
     * that length is cheaply derivable, otherwise a plain string token.
     *
     * @param  \Illuminate\Validation\Rules\Password  $rule
     * @return list<string>
     *
     * @SuppressWarnings("php:S3011")
     */
    private function passwordTokens(Password $rule): array
    {
        try {
            $min = (new \ReflectionProperty($rule, 'min'))->getValue($rule);
        } catch (\Throwable) {
            return ['string'];
        }

        return is_int($min) && $min > 0 ? ['string', 'min:' . $min] : ['string'];
    }
}
