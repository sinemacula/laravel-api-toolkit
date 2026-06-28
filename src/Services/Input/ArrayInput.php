<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Input;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;

/**
 * Typed accessor layer over a pre-validated attribute array.
 *
 * Provides the no-class escape hatch for callers who already hold a validated
 * array but do not want to define a dedicated typed input class. Validation is
 * the caller's responsibility; ArrayInput stores and exposes the snapshot as
 * given.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ArrayInput implements ServiceInput
{
    /**
     * Constructor.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(

        /** Immutable attribute snapshot supplied by the caller */
        private readonly array $attributes,
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
     * Returns an empty string when the key is absent. Non-scalar, non-null
     * values (arrays, objects) coerce to an empty string.
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
     * Returns zero when the key is absent. Non-scalar, non-null values (arrays,
     * objects) coerce to zero.
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
     * Returns false when the key is absent.
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
     * Returns an empty array when the key is absent.
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
