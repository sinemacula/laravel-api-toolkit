<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Reads the values of an `in:` rule argument as the validator compares them.
 *
 * The validator compares string forms, so a value is a number only when its
 * string form matches exactly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class InRuleValues
{
    /**
     * Resolve the enum for a field, using exact numbers on a numeric field.
     *
     * @param  string  $argument
     * @param  string|null  $type
     * @return array<int, float|int|string>
     */
    public function forField(string $argument, ?string $type): array
    {
        $values = $this->parse($argument);

        if (!in_array($type, ['integer', 'number'], true)) {
            return $values;
        }

        return array_map(fn (string $value): float|int|string => $this->exactNumber($value) ?? $value, $values);
    }

    /**
     * Resolve the enum for an array's items, listing any exact number too.
     *
     * @param  string  $argument
     * @return list<float|int|string>
     */
    public function forItems(string $argument): array
    {
        $enum = [];

        foreach ($this->parse($argument) as $value) {
            $enum[] = $value;
            $number = $this->exactNumber($value);

            if ($number === null) {
                continue;
            }

            $enum[] = $number;
        }

        return $enum;
    }

    /**
     * Split the argument into its values as the validator does.
     *
     * @param  string  $argument
     * @return array<int, string>
     */
    private function parse(string $argument): array
    {
        return array_filter(str_getcsv($argument, escape: '\\'), is_string(...));
    }

    /**
     * Resolve the number whose string form is exactly the given value.
     *
     * @param  string  $value
     * @return float|int|null
     */
    private function exactNumber(string $value): float|int|null
    {
        $number = str_contains($value, '.') ? (float) $value : (int) $value;

        return (string) $number === $value ? $number : null;
    }
}
