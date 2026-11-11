<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;

/**
 * Typed accessors over an attribute array the caller has already validated.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ArrayInput implements ServiceInput
{
    /**
     * Constructor.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(

        /** Immutable attribute snapshot supplied by the caller */
        private array $attributes,
    ) {}

    /**
     * Return the raw value for the given key, or the default when absent.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes)
            ? $this->attributes[$key]
            : $default;
    }

    /**
     * Return the value for the given key coerced to a string.
     *
     * An absent or non-scalar value gives an empty string.
     *
     * @param  string  $key
     * @return string
     */
    public function string(string $key): string
    {
        $value = $this->get($key, '');

        return is_scalar($value) || $value === null ? (string) $value : '';
    }

    /**
     * Return the value for the given key coerced to an integer.
     *
     * An absent or non-scalar value gives zero.
     *
     * @param  string  $key
     * @return int
     */
    public function integer(string $key): int
    {
        $value = $this->get($key, 0);

        return is_scalar($value) || $value === null ? (int) $value : 0;
    }

    /**
     * Return the value for the given key coerced to a boolean.
     *
     * @param  string  $key
     * @return bool
     */
    public function boolean(string $key): bool // phpcs:ignore SineMacula.NamingConventions.BooleanMethodName.NotPredicate
    {
        return (bool) $this->get($key, false);
    }

    /**
     * Return the value for the given key coerced to an array.
     *
     * @param  string  $key
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        return (array) $this->get($key, []);
    }

    /**
     * Return the full attribute snapshot.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return $this->attributes;
    }
}
