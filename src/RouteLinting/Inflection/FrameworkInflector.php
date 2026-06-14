<?php

namespace SineMacula\ApiToolkit\RouteLinting\Inflection;

use Illuminate\Support\Str;
use SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector;

/**
 * Framework-backed inflector adapter.
 *
 * Wraps `Illuminate\Support\Str` singularisation with a configured list of
 * uncountable words. Uncountables short-circuit before the framework inflector
 * so domain nouns like "media" or "data" are never mis-singularised or
 * incorrectly flagged as singular.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FrameworkInflector implements Inflector
{
    /**
     * Create a new framework inflector.
     *
     * @param  array<int, string>  $uncountables
     */
    public function __construct(private readonly array $uncountables = []) {}

    /**
     * Return the singular form of a segment, honouring configured uncountables.
     *
     * Returns the value unchanged when it is an uncountable word; otherwise
     * delegates to `Str::singular()`. An empty string is returned as-is.
     *
     * @param  string  $value
     * @return string
     */
    #[\Override]
    public function singular(string $value): string
    {
        if ($value === '' || in_array(strtolower($value), $this->uncountables, true)) {
            return $value;
        }

        return Str::singular($value);
    }

    /**
     * Determine whether a segment is already plural.
     *
     * Uncountables are always treated as plural-safe and return `true`. For
     * other words, a word is considered plural when its singular form differs
     * from the original. An empty string always returns `false`.
     *
     * @param  string  $value
     * @return bool
     */
    #[\Override]
    public function isPlural(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (in_array(strtolower($value), $this->uncountables, true)) {
            return true;
        }

        return Str::singular($value) !== $value;
    }
}
