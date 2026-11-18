<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Config;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;

/**
 * Reads toolkit settings with their type checked, and merges the shipped
 * defaults beneath the application's settings.
 *
 * Each read goes to the config repository, so a setting changed at runtime is
 * seen on the next read. A missing setting fails rather than reading as null.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ToolkitConfig
{
    /** @var string The key every toolkit setting sits under */
    public const string ROOT = 'api-toolkit';

    /** @var string What a setting holding a whole array accepts */
    public const string AN_ARRAY = 'an array';

    /** @var string What a string setting accepts */
    public const string A_NON_EMPTY_STRING = 'a non-empty string';

    /** @var string What a boolean setting accepts */
    private const string A_BOOLEAN = 'a boolean, or one of 1, 0, true, false, on, off, yes, no';

    /** @var string What a list setting accepts */
    private const string A_LIST = 'a list of non-empty strings';

    /** @var string What a nullable setting accepts besides its type */
    private const string OR_NULL = ', or null';

    /** @var list<string> The settings whose value is taken whole, never merged or walked */
    private const array OPAQUE = [
        'exceptions.map',
        'resources.resource_map',
        'openapi.info',
        'openapi.servers',
        'openapi.audiences',
        'openapi.security.drivers',
    ];

    /** @var array<int|string, bool> The accepted spellings of a boolean */
    private const array BOOLEANS = [
        '1'     => true,
        'true'  => true,
        'on'    => true,
        'yes'   => true,
        '0'     => false,
        'false' => false,
        'off'   => false,
        'no'    => false,
    ];

    /**
     * Read a boolean setting.
     *
     * @param  string  $key
     * @return bool
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readBoolean(string $key): bool
    {
        $value = self::readValue($key);

        return self::toBoolean($value) ?? throw InvalidConfigurationException::mustBe(self::key($key), self::A_BOOLEAN, $value);
    }

    /**
     * Read a boolean setting, or null.
     *
     * @param  string  $key
     * @return bool|null
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readNullableBoolean(string $key): ?bool
    {
        $value = self::readValue($key);

        if ($value === null) {
            return null;
        }

        return self::toBoolean($value) ?? throw InvalidConfigurationException::mustBe(self::key($key), self::A_BOOLEAN . self::OR_NULL, $value);
    }

    /**
     * Read an integer setting of at least the given minimum.
     *
     * @param  string  $key
     * @param  int  $minimum
     * @return int
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readInteger(string $key, int $minimum): int
    {
        $value = self::readValue($key);

        return self::toInteger($value, $minimum)
            ?? throw InvalidConfigurationException::mustBe(self::key($key), sprintf('an integer of at least %d', $minimum), $value);
    }

    /**
     * Read a cap setting, where null and 0 disable the cap.
     *
     * @param  string  $key
     * @return int
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readCap(string $key): int
    {
        $value = self::readValue($key);

        if ($value === null) {
            return 0;
        }

        return self::toInteger($value, 0)
            ?? throw InvalidConfigurationException::mustBe(self::key($key), 'an integer of at least 0, or null', $value);
    }

    /**
     * Read a non-empty string setting.
     *
     * @param  string  $key
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readString(string $key): string
    {
        $value = self::readValue($key);

        if (!is_string($value) || $value === '') {
            throw InvalidConfigurationException::mustBe(self::key($key), self::A_NON_EMPTY_STRING, $value);
        }

        return $value;
    }

    /**
     * Read a string setting where null and an empty string mean none.
     *
     * @param  string  $key
     * @return string|null
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readNullableString(string $key): ?string
    {
        $value = self::readValue($key);

        if ($value !== null && !is_string($value)) {
            throw InvalidConfigurationException::mustBe(self::key($key), 'a string, or null', $value);
        }

        return $value === '' ? null : $value;
    }

    /**
     * Read a setting naming one case of the given enum.
     *
     * @template TEnum of \BackedEnum
     *
     * @param  string  $key
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readEnum(string $key, string $enum): \BackedEnum
    {
        $value = self::readValue($key);

        return (is_string($value) ? $enum::tryFrom($value) : null)
            ?? throw InvalidConfigurationException::mustBe(self::key($key), self::cases($enum), $value);
    }

    /**
     * Read a setting holding a list of non-empty strings.
     *
     * @param  string  $key
     * @return list<string>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readList(string $key): array
    {
        return self::checkedList($key, self::readValue($key), self::A_LIST);
    }

    /**
     * Read a setting holding a list of non-empty strings, or null.
     *
     * @param  string  $key
     * @return list<string>|null
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readNullableList(string $key): ?array
    {
        $value = self::readValue($key);

        return $value === null ? null : self::checkedList($key, $value, self::A_LIST . self::OR_NULL);
    }

    /**
     * Read a setting holding an array taken as a whole.
     *
     * @param  string  $key
     * @return array<array-key, mixed>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function readMap(string $key): array
    {
        $value = self::readValue($key);

        if (!is_array($value)) {
            throw InvalidConfigurationException::mustBe(self::key($key), self::AN_ARRAY, $value);
        }

        return $value;
    }

    /**
     * Return the full key of the given setting.
     *
     * @param  string  $key
     * @return string
     */
    public static function key(string $key): string
    {
        return self::ROOT . '.' . $key;
    }

    /**
     * Load the shipped settings, required afresh as the file reads the
     * environment.
     *
     * @return array<string, mixed>
     *
     * @SuppressWarnings("php:S2003")
     * @SuppressWarnings("php:S4833")
     */
    public static function defaults(): array
    {
        /** @var array<string, mixed> */
        return require dirname(__DIR__, 2) . '/config/api-toolkit.php';
    }

    /**
     * Determine whether the shipped value at the given path is a section of
     * further settings.
     *
     * @param  string  $path
     * @param  mixed  $default
     * @return bool
     *
     * @phpstan-assert-if-true array<array-key, mixed> $default
     */
    public static function isSection(string $path, mixed $default): bool
    {
        return is_array($default) && !array_is_list($default) && !in_array($path, self::OPAQUE, true);
    }

    /**
     * Merge every shipped setting the application lacks beneath its own.
     *
     * Lists and opaque settings are taken whole, so a configured list replaces
     * the shipped one; a section the application set to a non-array is left for
     * validation to reject.
     *
     * @return void
     */
    public static function merge(): void
    {
        $configured = Config::get(self::ROOT);

        if ($configured !== null && !is_array($configured)) {
            return;
        }

        Config::set(self::ROOT, self::mergeBeneath(self::defaults(), $configured ?? [], ''));
    }

    /**
     * Read the raw value of a setting, failing when it is not set.
     *
     * @param  string  $key
     * @return mixed
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    private static function readValue(string $key): mixed
    {
        $key = self::key($key);

        if (!Config::has($key)) {
            throw InvalidConfigurationException::missing($key);
        }

        return Config::get($key);
    }

    /**
     * Fill every shipped setting missing from the configured ones.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $configured
     * @param  string  $path
     * @return array<array-key, mixed>
     */
    private static function mergeBeneath(array $defaults, array $configured, string $path): array
    {
        foreach ($defaults as $name => $default) {

            $child = ltrim($path . '.' . $name, '.');

            if (!array_key_exists($name, $configured)) {
                $configured[$name] = $default;
            } elseif (self::isSection($child, $default) && is_array($configured[$name])) {
                $configured[$name] = self::mergeBeneath($default, $configured[$name], $child);
            }
        }

        return $configured;
    }

    /**
     * Convert a boolean, or one of its accepted spellings.
     *
     * @param  mixed  $value
     * @return bool|null
     */
    private static function toBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_int($value) || is_string($value) ? self::BOOLEANS[strtolower(trim((string) $value))] ?? null : null;
    }

    /**
     * Check that the value of the given setting is a list of non-empty strings.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $expectation
     * @return list<string>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    private static function checkedList(string $key, mixed $value, string $expectation): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw InvalidConfigurationException::mustBe(self::key($key), $expectation, $value);
        }

        foreach ($value as $index => $entry) {
            if (!is_string($entry) || $entry === '') {
                throw InvalidConfigurationException::mustBe(self::key($key . '.' . $index), self::A_NON_EMPTY_STRING, $entry);
            }
        }

        return $value;
    }

    /**
     * Convert an integer or a string of digits of at least the given minimum.
     *
     * @param  mixed  $value
     * @param  int  $minimum
     * @return int|null
     */
    private static function toInteger(mixed $value, int $minimum): ?int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $minimum ? $value : null;
    }

    /**
     * Describe the cases of the given enum.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return string
     */
    private static function cases(string $enum): string
    {
        return 'one of ' . implode(', ', array_column($enum::cases(), 'value'));
    }
}
