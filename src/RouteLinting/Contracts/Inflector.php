<?php

namespace SineMacula\ApiToolkit\RouteLinting\Contracts;

/**
 * Outbound port for word inflection, honouring configured uncountables.
 *
 * Isolates the domain's singularisation and plurality checks from the
 * framework inflector. The adapter honours the uncountables list supplied
 * by the rule configuration so domain nouns like "media" or "data" are
 * never mis-singularised.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Inflector
{
    /**
     * Return the singular form of a word, honouring configured uncountables.
     *
     * @param  string  $word
     * @return string
     */
    public function singular(string $word): string;

    /**
     * Determine whether a word is already plural (uncountables are treated as plural-safe).
     *
     * @param  string  $word
     * @return bool
     */
    public function isPlural(string $word): bool;
}
