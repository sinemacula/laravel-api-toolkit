<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input\Rules;

use Illuminate\Contracts\Validation\ImplicitRule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use SineMacula\ApiToolkit\Support\PureEnumName;

/**
 * Validates that a payload value casts to its parameter's enum in canonical
 * form.
 *
 * Laravel's enum rule passes "01" for an int-backed enum and skips an empty
 * string, so both would otherwise reach the cast unchecked.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class Enum extends EnumRule implements ImplicitRule // @phpstan-ignore class.implementsDeprecatedInterface
{
    /**
     * Resolve the enum case a value names in canonical form, if any.
     *
     * @param  class-string<\UnitEnum>  $enum
     * @param  mixed  $value
     * @return \UnitEnum|null
     */
    public static function resolve(string $enum, mixed $value): ?\UnitEnum
    {
        if (!is_a($enum, \BackedEnum::class, true)) {
            return is_string($value) ? PureEnumName::tryFrom($enum, $value) : null;
        }

        $backing = self::backingValue($enum, $value);

        return $backing === null ? null : $enum::tryFrom($backing);
    }

    /**
     * Determine whether the value is absent, a case of the enum, or names one.
     *
     * @param  mixed  $attribute
     * @param  mixed  $value
     * @return bool
     */
    #[\Override]
    public function passes(mixed $attribute, mixed $value): bool
    {
        return $value === null || $value instanceof $this->type || self::resolve($this->type, $value) !== null;
    }

    /**
     * Read a value as a backed enum's backing value, in canonical form only.
     *
     * An int-backed enum accepts "-3" but not "03", "+3" or "3.0".
     *
     * @param  class-string<\BackedEnum>  $enum
     * @param  mixed  $value
     * @return int|string|null
     */
    private static function backingValue(string $enum, mixed $value): int|string|null
    {
        if ((string) (new \ReflectionEnum($enum))->getBackingType() !== 'int') {
            return is_string($value) ? $value : null;
        }

        return match (true) {
            is_int($value)                                        => $value,
            is_string($value) && (string) (int) $value === $value => (int) $value,
            default                                               => null,
        };
    }
}
