<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Support;

/**
 * Owns the wire form of a pure (unbacked) enum case: its lowercase case name.
 *
 * Matched exactly, so PLATFORM and Platform are refused. Case names that
 * collide once lowercased fail loud.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class PureEnumName
{
    /**
     * Return the wire name of the given enum case.
     *
     * @param  \UnitEnum  $case
     * @return string
     *
     * @throws \LogicException
     */
    public static function of(\UnitEnum $case): string
    {
        self::cases($case::class);

        return strtolower($case->name);
    }

    /**
     * Resolve the case of the given enum whose wire name is exactly the value,
     * or null when no case matches.
     *
     * @param  class-string<\UnitEnum>  $enum
     * @param  string  $value
     * @return \UnitEnum|null
     *
     * @throws \LogicException
     */
    public static function tryFrom(string $enum, string $value): ?\UnitEnum
    {
        return self::cases($enum)[$value] ?? null;
    }

    /**
     * Map each wire name of the enum to its case, failing when two cases share
     * a wire name.
     *
     * @param  class-string<\UnitEnum>  $enum
     * @return array<string, \UnitEnum>
     *
     * @throws \LogicException
     */
    private static function cases(string $enum): array
    {
        $cases = [];

        foreach ($enum::cases() as $case) {

            $name = strtolower($case->name);

            if (isset($cases[$name])) {
                throw new \LogicException(sprintf('The cases %s and %s of %s share the wire name "%s"', $cases[$name]->name, $case->name, $enum, $name));
            }

            $cases[$name] = $case;
        }

        return $cases;
    }
}
