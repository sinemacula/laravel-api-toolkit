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
     * other type.
     *
     * @param  string  $type
     * @param  array<array-key, mixed>  $node
     * @return mixed
     */
    public function scalar(string $type, array $node): mixed
    {
        return match ($type) {
            'string'  => $this->string($node),
            'integer' => (int) ceil($this->number($node, 1)),
            'number'  => $this->number($node, 1.5),
            'boolean' => true,
            default   => null,
        };
    }

    /**
     * Build a string in the node's format, else one sized to its length bounds.
     *
     * @param  array<array-key, mixed>  $node
     * @return string
     */
    private function string(array $node): string
    {
        $format = $node['format'] ?? null;

        if (is_string($format) && array_key_exists($format, self::FORMATTED_STRINGS)) {
            return self::FORMATTED_STRINGS[$format];
        }

        $value = str_pad('string', is_int($node['minLength'] ?? null) ? $node['minLength'] : 0, 'x');

        return is_int($node['maxLength'] ?? null) ? substr($value, 0, $node['maxLength']) : $value;
    }

    /**
     * Clamp a number into the node's bounds, then raise it to the next multiple
     * the node requires.
     *
     * @param  array<array-key, mixed>  $node
     * @param  float|int  $value
     * @return float|int
     */
    private function number(array $node, float|int $value): float|int
    {
        $value = max($value, $this->bound($node, 'minimum') ?? $value, ($this->bound($node, 'exclusiveMinimum') ?? $value - 1) + 1);
        $value = min($value, $this->bound($node, 'maximum') ?? $value, ($this->bound($node, 'exclusiveMaximum') ?? $value + 1) - 1);
        $step  = $this->bound($node, 'multipleOf');

        return $step === null || $step <= 0 ? $value : ceil($value / $step) * $step;
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
