<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Carries an exact list of enum values from the rule normaliser to the field
 * schema builder.
 *
 * A narrowed enum rule accepts values the validator never reads from an `in:`
 * list, so they travel as JSON instead: each value keeps its type and every
 * character, and an empty list stays distinguishable from no list at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EnumValuesToken
{
    /** The rule name the token is written under */
    public const string NAME = 'enum-values';

    /**
     * Build the token listing the given values.
     *
     * @param  list<int|string>  $values
     * @return string
     */
    public function token(array $values): string
    {
        return self::NAME . ':' . json_encode($values);
    }

    /**
     * Read the values from a token's argument, or null when it does not hold a
     * list of values.
     *
     * @param  string  $argument
     * @return list<int|string>|null
     */
    public function values(string $argument): ?array
    {
        $values = json_decode($argument, true);

        if (!is_array($values) || !array_is_list($values)) {
            return null;
        }

        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                return null;
            }
        }

        return $values;
    }
}
