<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Support;

/**
 * Resolves a backed enum case from a value the way Laravel's enum rule does.
 *
 * Laravel's enum rule calls tryFrom() under coercive typing, so it accepts more
 * than the exact backing value: a numeric string such as "1", " 1" or "1.0"
 * names the case backed by 1, a boolean or an integral float is read as an
 * integer, and an integer names the string-backed case of the same digits.
 * Under strict typing tryFrom() refuses all of those, so a payload hydrated
 * after the rule passed would fail on a value the rule accepted. This repeats
 * the coercion explicitly, so the case resolved matches the one the rule
 * validated, and a value coercion refuses resolves to no case rather than a
 * type error.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class BackedEnumValue
{
    /**
     * Resolve the case of the enum the value names under coercive typing, or
     * null when it names none or cannot be coerced to the backing type.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @param  mixed  $value
     * @return \BackedEnum|null
     */
    public static function tryFrom(string $enum, mixed $value): ?\BackedEnum
    {
        $backing = (string) (new \ReflectionEnum($enum))->getBackingType() === 'int'
            ? self::toInt($value)
            : self::toString($value);

        return $backing === null ? null : $enum::tryFrom($backing);
    }

    /**
     * Coerce a value to a string-backed enum's backing type: a stringable
     * object or a scalar becomes its string form, a boolean or a float that
     * fits an integer by way of that integer.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private static function toString(mixed $value): ?string
    {
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        $value = self::toScalar($value);

        return $value === null ? null : (string) $value;
    }

    /**
     * Coerce a value to an int-backed enum's backing type: an integer as-is,
     * and a numeric string, boolean or float whose value fits an integer
     * truncated to it.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private static function toInt(mixed $value): ?int
    {
        $value = self::toScalar($value);

        if (!is_numeric($value)) {
            return null;
        }

        $number = $value + 0;

        return is_int($number) || self::fitsInt($number) ? (int) $number : null;
    }

    /**
     * Reduce a scalar to the integer or string tryFrom() first coerces it to: a
     * boolean or a float that fits an integer becomes an integer, any other
     * float its string form, and a non-scalar is refused.
     *
     * @param  mixed  $value
     * @return int|string|null
     */
    private static function toScalar(mixed $value): int|string|null
    {
        return match (true) {
            is_int($value), is_string($value) => $value,
            is_bool($value)                   => (int) $value,
            is_float($value)                  => self::fitsInt($value) ? (int) $value : (string) $value,
            default                           => null,
        };
    }

    /**
     * Determine whether a float lies within the integer range.
     *
     * @param  float  $number
     * @return bool
     */
    private static function fitsInt(float $number): bool
    {
        return $number >= PHP_INT_MIN && $number < PHP_INT_MAX;
    }
}
