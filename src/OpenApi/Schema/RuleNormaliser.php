<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

/**
 * Flattens a Laravel rule definition into a list of string tokens.
 *
 * A field's rules may be a pipe-delimited string, an array of tokens, or a
 * mixture of tokens and rule objects. This normaliser reduces every form to a
 * single flat list of string tokens so the field-schema builder has one shape
 * to interpret. Rule objects are expanded best-effort: an Enum contributes an
 * `in:` token carrying its backing values and a Password contributes `string`
 * plus its minimum length; any other object is dropped, keeping the field while
 * discarding the constraint it could not translate.
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
     * Normalise an array rule definition, expanding each element in turn.
     *
     * @param  array<array-key, mixed>  $definition
     * @return list<string>
     */
    private function normaliseArray(array $definition): array
    {
        $tokens = [];

        foreach ($definition as $element) {
            if (is_string($element)) {
                $tokens = array_merge($tokens, $this->splitPipes($element));
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
            $rule instanceof Password => $this->passwordTokens($rule),
            default                   => [],
        };
    }

    /**
     * Expand an Enum rule into a single `in:` token carrying its backing
     * values, or nothing when the values cannot be read.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @return list<string>
     *
     * @SuppressWarnings("php:S3011")
     */
    private function enumTokens(Enum $rule): array
    {
        try {
            $type = (new \ReflectionProperty($rule, 'type'))->getValue($rule);
        } catch (\Throwable) {
            return [];
        }

        if (!is_string($type) || !enum_exists($type)) {
            return [];
        }

        $values = [];

        foreach ($type::cases() as $case) {
            if (!($case instanceof \BackedEnum)) {
                continue;
            }

            $values[] = (string) $case->value;
        }

        return $values === [] ? [] : ['in:' . implode(',', $values)];
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
