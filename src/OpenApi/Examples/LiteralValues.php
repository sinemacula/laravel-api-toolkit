<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

/**
 * Reads the values a schema node lists, and builds the value of a scalar type
 * within the node's bounds.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class LiteralValues
{
    /** The value of a string in each format with a fixed example */
    private const array FORMATTED_STRINGS = [
        'email'     => 'user@example.com',
        'uri'       => 'https://example.com',
        'uuid'      => '123e4567-e89b-42d3-a456-426614174000',
        'ulid'      => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'date'      => '2025-01-01',
        'date-time' => '2025-01-01T00:00:00Z',
        'time'      => '00:00:00Z',
        'ipv4'      => '192.0.2.1',
        'ipv6'      => '2001:db8::1',
        'hostname'  => 'example.com',
        'byte'      => 'c3RyaW5n',
    ];

    /**
     * Yield the values the node lists: its first example, its deprecated
     * example, its const and its enum values, null last.
     *
     * @param  array<array-key, mixed>  $node
     * @return iterable<mixed>
     */
    public function listed(array $node): iterable
    {
        if (is_array($node['examples'] ?? null) && array_key_exists(0, $node['examples'])) {
            yield $node['examples'][0];
        }

        if (array_key_exists('example', $node)) {
            yield $node['example'];
        }

        if (array_key_exists('const', $node)) {
            yield $node['const'];
        }

        $enum = is_array($node['enum'] ?? null) ? $node['enum'] : [];

        yield from array_filter($enum, static fn (mixed $value): bool => $value !== null);

        if (!in_array(null, $enum, true)) {
            return;
        }

        yield null;
    }

    /**
     * Build the value of a scalar type within the node's bounds, null for any
     * other type; each later ordinal varies a plain string, a number or a
     * boolean, for items that must differ.
     *
     * @param  string  $type
     * @param  array<array-key, mixed>  $node
     * @param  int  $ordinal
     * @return mixed
     */
    public function scalar(string $type, array $node, int $ordinal = 0): mixed
    {
        return match ($type) {
            'string'  => $this->string($node, $ordinal),
            'integer' => $this->varied($this->number($node, 1, true), $node, $ordinal),
            'number'  => $this->varied($this->number($node, 1.5, false), $node, $ordinal),
            'boolean' => $ordinal === 0,
            default   => null,
        };
    }

    /**
     * Yield the values of a scalar type after its first, for items that must
     * differ, stopping once a value repeats or the count is reached.
     *
     * @param  string  $type
     * @param  array<array-key, mixed>  $node
     * @param  int  $count
     * @return iterable<mixed>
     */
    public function variants(string $type, array $node, int $count): iterable
    {
        $previous = $this->scalar($type, $node);

        for ($ordinal = 1; $ordinal < $count; $ordinal++) {

            $variant = $this->scalar($type, $node, $ordinal);

            if ($variant === $previous) {
                return [];
            }

            yield $previous = $variant;
        }
    }

    /**
     * Build a string in the node's format, else one sized to its length bounds.
     *
     * @param  array<array-key, mixed>  $node
     * @param  int  $ordinal
     * @return string
     */
    private function string(array $node, int $ordinal): string
    {
        $format = $node['format'] ?? null;

        if (is_string($format) && array_key_exists($format, self::FORMATTED_STRINGS)) {
            return self::FORMATTED_STRINGS[$format];
        }

        $value = str_pad('string' . ($ordinal === 0 ? '' : $ordinal + 1), is_int($node['minLength'] ?? null) ? $node['minLength'] : 0, 'x');

        return is_int($node['maxLength'] ?? null) ? substr($value, 0, $node['maxLength']) : $value;
    }

    /**
     * Clamp a number into the node's bounds, stepping a whole unit inside an
     * exclusive bound, and raise it to the next multiple the node requires, an
     * integer one for an integer; else take the middle of a bounded range.
     *
     * @param  array<array-key, mixed>  $node
     * @param  float|int  $value
     * @param  bool  $integer
     * @return float|int
     */
    private function number(array $node, float|int $value, bool $integer): float|int
    {
        $value  = max($value, $this->bound($node, 'minimum') ?? $value, ($this->bound($node, 'exclusiveMinimum') ?? $value - 1) + 1);
        $value  = min($value, $this->bound($node, 'maximum') ?? $value, ($this->bound($node, 'exclusiveMaximum') ?? $value + 1) - 1);
        $value  = $this->rounded($node, $value, $integer);
        $middle = $this->fits($node, $value) ? null : $this->middle($node);

        return $middle === null ? $value : $this->rounded($node, $middle, $integer);
    }

    /**
     * Raise a number to the next multiple the node requires, then to the next
     * integer for an integer.
     *
     * @param  array<array-key, mixed>  $node
     * @param  float|int  $value
     * @param  bool  $integer
     * @return float|int
     */
    private function rounded(array $node, float|int $value, bool $integer): float|int
    {
        $step  = $this->bound($node, 'multipleOf');
        $value = $step === null || $step <= 0 ? $value : ceil($value / $step) * $step;

        return $integer ? (int) ceil($value) : $value;
    }

    /**
     * Determine whether a number lies within the node's bounds.
     *
     * @param  array<array-key, mixed>  $node
     * @param  float|int  $value
     * @return bool
     */
    private function fits(array $node, float|int $value): bool
    {
        return $value >= ($this->bound($node, 'minimum') ?? $value)
            && $value <= ($this->bound($node, 'maximum') ?? $value)
            && $value > ($this->bound($node, 'exclusiveMinimum') ?? $value - 1)
            && $value < ($this->bound($node, 'exclusiveMaximum') ?? $value + 1);
    }

    /**
     * Find the middle of the range the node's bounds leave, null when a side is
     * unbounded.
     *
     * @param  array<array-key, mixed>  $node
     * @return float|int|null
     */
    private function middle(array $node): float|int|null
    {
        $lower = max($this->bound($node, 'minimum') ?? -INF, $this->bound($node, 'exclusiveMinimum') ?? -INF);
        $upper = min($this->bound($node, 'maximum') ?? INF, $this->bound($node, 'exclusiveMaximum') ?? INF);

        return is_infinite($lower) || is_infinite($upper) ? null : $lower + ($upper - $lower) / 2;
    }

    /**
     * Vary a number by its ordinal, in steps of the node's multiple, else one.
     *
     * @param  float|int  $value
     * @param  array<array-key, mixed>  $node
     * @param  int  $ordinal
     * @return float|int
     */
    private function varied(float|int $value, array $node, int $ordinal): float|int
    {
        $step = $this->bound($node, 'multipleOf');

        return $value + $ordinal * ($step === null || $step <= 0 ? 1 : $step);
    }

    /**
     * Read a numeric keyword of the node.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $keyword
     * @return float|int|null
     */
    private function bound(array $node, string $keyword): float|int|null
    {
        $bound = $node[$keyword] ?? null;

        return is_int($bound) || is_float($bound) ? $bound : null;
    }
}
