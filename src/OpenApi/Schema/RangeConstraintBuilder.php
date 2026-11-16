<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Builds the size, bound and divisor keywords of a field's range rules.
 *
 * Every bound applies, so the tightest is kept, and a numeric field is also
 * bounded by its literal comparisons and digit counts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class RangeConstraintBuilder
{
    /** The types whose bounds are numeric values */
    private const array NUMERIC_TYPES = ['integer', 'number'];

    /** The most digits an integer bound is derived for */
    private const int MAX_DIGITS = 18;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RuleArguments  $arguments
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\FieldPresence  $presence
     */
    public function __construct(

        /** The reader of the arguments the field's rules carry. */
        private RuleArguments $arguments = new RuleArguments,

        /** The reader of how the field's rules govern its presence. */
        private FieldPresence $presence = new FieldPresence,
    ) {}

    /**
     * Build the range keywords of the field's rules for its type.
     *
     * @param  list<string>  $tokens
     * @param  string  $type
     * @return array<string, float|int>
     */
    public function build(array $tokens, string $type): array
    {
        return [...$this->sizeKeywords($tokens, $type), ...$this->numericKeywords($tokens, $type)];
    }

    /**
     * Build the size keyword pair matching the field's type.
     *
     * @param  list<string>  $tokens
     * @param  string  $type
     * @return array<string, float|int>
     */
    private function sizeKeywords(array $tokens, string $type): array
    {
        $keys = $this->sizeKeys($type);

        if ($keys === null) {
            return [];
        }

        $schema = [];

        [$min, $max] = $this->bounds($tokens, $type);

        if ($type === 'array' && $this->presence->rejectsEmpty($tokens)) {
            $min = (string) max((int) $min, 1);
        }

        if ($min !== null) {
            $schema[$keys[0]] = $this->cast($min, $type);
        }

        if ($max !== null) {
            $schema[$keys[1]] = $this->cast($max, $type);
        }

        return $schema;
    }

    /**
     * Build the exclusive bounds and divisor of a numeric field's literal
     * comparisons.
     *
     * @param  list<string>  $tokens
     * @param  string  $type
     * @return array<string, float|int>
     */
    private function numericKeywords(array $tokens, string $type): array
    {
        if (!in_array($type, self::NUMERIC_TYPES, true)) {
            return [];
        }

        $schema = [];

        $above = $this->tightest($this->arguments->of($tokens, 'gt'), true);
        $below = $this->tightest($this->arguments->of($tokens, 'lt'), false);

        if ($above !== null) {
            $schema['exclusiveMinimum'] = $this->numericValue($above);
        }

        if ($below !== null) {
            $schema['exclusiveMaximum'] = $this->numericValue($below);
        }

        $divisor = $this->arguments->of($tokens, 'multiple_of')[0] ?? null;

        if (is_numeric($divisor) && (float) $divisor !== 0.0) {
            $schema['multipleOf'] = abs($this->numericValue($divisor));
        }

        return $schema;
    }

    /**
     * Map a resolved type to its minimum/maximum keyword pair.
     *
     * @param  string  $type
     * @return array{0: string, 1: string}|null
     */
    private function sizeKeys(string $type): ?array
    {
        return match ($type) {
            'string'            => ['minLength', 'maxLength'],
            'integer', 'number' => ['minimum', 'maximum'],
            'array'             => ['minItems', 'maxItems'],
            default             => null,
        };
    }

    /**
     * Resolve the tightest minimum and maximum bounds, as the validator applies
     * every one.
     *
     * A numeric field is also bounded by its inclusive comparisons and digit
     * counts.
     *
     * @param  list<string>  $tokens
     * @param  string  $type
     * @return array{0: string|null, 1: string|null}
     */
    private function bounds(array $tokens, string $type): array
    {
        $lower = $this->arguments->of($tokens, 'min');
        $upper = $this->arguments->of($tokens, 'max');

        foreach ($this->arguments->of($tokens, 'between') as $between) {
            [$lower[], $upper[]] = array_pad(explode(',', $between), 2, '');
        }

        foreach ($this->arguments->of($tokens, 'size') as $size) {
            $lower[] = $upper[] = $size;
        }

        if (in_array($type, self::NUMERIC_TYPES, true)) {
            [$lower, $upper] = $this->withNumericBounds($tokens, $lower, $upper);
        }

        return [$this->tightest($lower, true), $this->tightest($upper, false)];
    }

    /**
     * Add a numeric field's inclusive comparisons and digit-count bounds.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $lower
     * @param  list<string>  $upper
     * @return array{0: list<string>, 1: list<string>}
     */
    private function withNumericBounds(array $tokens, array $lower, array $upper): array
    {
        $lower = [...$lower, ...$this->arguments->of($tokens, 'gte')];
        $upper = [...$upper, ...$this->arguments->of($tokens, 'lte')];

        foreach ($this->arguments->of($tokens, 'digits') as $count) {
            $lower[] = $this->digitBound($this->digitCount($count), true);
            $upper[] = $this->digitBound($this->digitCount($count), false);
        }

        foreach ($this->arguments->of($tokens, 'digits_between') as $range) {
            [$least, $most] = array_pad(explode(',', $range), 2, '');

            $lower[] = $this->digitBound($this->digitCount($least), true);
            $upper[] = $this->digitBound($this->digitCount($most), false);
        }

        return [$lower, $upper];
    }

    /**
     * Read a digit-count argument, or null when an integer bound cannot hold
     * it.
     *
     * @param  string  $count
     * @return int|null
     */
    private function digitCount(string $count): ?int
    {
        if (!ctype_digit($count)) {
            return null;
        }

        $digits = (int) $count;

        return $digits <= self::MAX_DIGITS ? $digits : null;
    }

    /**
     * Resolve the smallest or largest integer written with the given number of
     * digits.
     *
     * @param  int|null  $digits
     * @param  bool  $lower
     * @return string
     */
    private function digitBound(?int $digits, bool $lower): string
    {
        return match (true) {
            $digits === null => '',
            !$lower          => str_repeat('9', $digits),
            $digits > 1      => '1' . str_repeat('0', $digits - 1),
            default          => '0',
        };
    }

    /**
     * Pick the tightest of the numeric bound candidates.
     *
     * A field reference or other non-numeric candidate is skipped.
     *
     * @param  list<string>  $candidates
     * @param  bool  $lower
     * @return string|null
     */
    private function tightest(array $candidates, bool $lower): ?string
    {
        $bound = null;

        foreach ($candidates as $candidate) {
            if (!is_numeric($candidate) || ($bound !== null && ($lower ? $candidate <= $bound : $candidate >= $bound))) {
                continue;
            }

            $bound = $candidate;
        }

        return $bound;
    }

    /**
     * Cast a size argument to the numeric shape matching the field's type.
     *
     * @param  string  $value
     * @param  string  $type
     * @return float|int
     */
    private function cast(string $value, string $type): float|int
    {
        return in_array($type, self::NUMERIC_TYPES, true) ? $this->numericValue($value) : (int) $value;
    }

    /**
     * Cast a numeric string to an integer when one holds it exactly, otherwise
     * a float.
     *
     * @param  string  $value
     * @return float|int
     */
    private function numericValue(string $value): float|int
    {
        return strpbrk($value, '.eE') === false && abs((float) $value) < PHP_INT_MAX ? (int) $value : (float) $value;
    }
}
