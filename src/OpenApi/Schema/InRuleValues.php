<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Reads the values of an `in:` rule argument as the validator compares them.
 *
 * The argument is parsed with the same CSV rules the validator applies, so a
 * quoted value keeps its commas and doubled quotes. The validator compares an
 * input's string form against these strings, so a value is offered as a number
 * only when that number's string form is exactly the value: on a numeric field
 * it replaces the string, and on an array's items, where each element is
 * compared the same way, both forms are listed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class InRuleValues
{
    /**
     * Resolve the enum for a field of the given type, emitting exact numbers in
     * place of their strings on an integer or number field.
     *
     * @param  string  $argument
     * @param  string|null  $type
     * @return list<float|int|string>
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
     * Resolve the enum for an array's items, listing each value as a string
     * and, when it has one, as its exact number too.
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
     * Split the argument into its values as the validator does, an empty
     * argument yielding none.
     *
     * @param  string  $argument
     * @return list<string>
     */
    private function parse(string $argument): array
    {
        $values = [];

        foreach (str_getcsv($argument, escape: '\\') as $value) {
            if ($value === null) {
                continue;
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * Resolve the number whose string form is exactly the given value, or null
     * when none is.
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
