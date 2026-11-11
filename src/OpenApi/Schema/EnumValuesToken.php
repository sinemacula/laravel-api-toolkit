<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Carries exact enum values from the rule normaliser to the schema builder.
 *
 * Encoded as JSON so values keep their type and an empty list stays distinct.
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
     * Read the values from a token's argument, or null when it holds no list.
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
