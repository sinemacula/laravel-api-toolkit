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
     * Return the singular form of a segment, honouring configured uncountables.
     *
     * @param  string  $value
     * @return string
     */
    public function singular(string $value): string;

    /**
     * Determine whether a segment is already plural (uncountables are treated as plural-safe).
     *
     * @param  string  $value
     * @return bool
     */
    public function isPlural(string $value): bool;
}
