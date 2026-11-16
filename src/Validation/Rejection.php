<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Validation;

use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * A validation failure carrying one of the toolkit's client-facing messages.
 *
 * Each message is read from the translator when it is raised, so it follows the
 * locale of the request that raised it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Rejection extends ValidationException
{
    /** @var string The translation group holding the toolkit's validation messages */
    private const string GROUP = 'api-toolkit::validation.';

    /**
     * Resolve the message under the given key in the current locale.
     *
     * An override that turns the line into an array yields the full key.
     *
     * @param  string  $key
     * @param  array<string, int|string>  $replace
     * @return string
     */
    public static function message(string $key, array $replace = []): string
    {
        $message = Lang::get(self::GROUP . $key, $replace);

        return is_string($message) ? $message : self::GROUP . $key;
    }

    /**
     * Build the rejection naming the rejected position and the reason.
     *
     * @param  string  $position
     * @param  string  $key
     * @param  array<string, int|string>  $replace
     * @return self
     */
    public static function at(string $position, string $key, array $replace = []): self
    {
        return self::withMessages([$position => self::message($key, $replace)]);
    }
}
