<?php

namespace SineMacula\ApiToolkit\RouteLinting\Rules\Support;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector;

/**
 * Deterministic 6-step pipeline that reduces a URI to candidate verb-test words.
 *
 * The pipeline is a pure function of its inputs — no randomness, no external
 * state. Steps: split on `/`, drop route parameters, drop version/prefix
 * segments, decompose compound segments across camelCase/kebab/snake/dot
 * boundaries, lowercase every word, then singularise via the injected
 * Inflector port (uncountable words bypass singularisation and pass through
 * unchanged).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SegmentNormaliser
{
    /**
     * Create a new segment normaliser.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector  $inflector
     */
    public function __construct(private Inflector $inflector) {}

    /**
     * Reduce a raw URI path to its candidate verb-test words.
     *
     * Applies the 6-step pipeline in order: split path, drop route parameters,
     * drop version/prefix segments, decompose compound segments, lowercase, and
     * singularise. Uncountable words are passed through unchanged. Returns the
     * flat ordered list of candidate words across all surviving segments.
     *
     * @param  string  $uri
     * @param  array<int, string>  $uncountables
     * @return array<int, string>
     */
    public function normalise(string $uri, array $uncountables): array
    {
        // Step 1 — split on '/' and discard empty segments
        $segments = array_values(array_filter(explode('/', $uri), fn (string $s): bool => $s !== ''));

        // Step 2 — drop route parameters (wrapped in braces, optional or required)
        $segments = array_values(array_filter($segments, fn (string $s): bool => !preg_match('/^\{[^}]+}$/', $s)));

        // Step 3 — drop version segments (v1, v2, …) and the literal 'api' prefix
        $segments = array_values(array_filter($segments, fn (string $s): bool => !preg_match('/^v\d+$/i', $s) && strtolower($s) !== 'api'));

        // Step 4 — decompose compound segments across camelCase, kebab, snake, and dot boundaries
        $words = [];

        foreach ($segments as $segment) {
            // Insert a space before each uppercase letter preceded by a lowercase letter (camelCase boundary)
            $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $segment) ?? $segment;

            // Split on whitespace, hyphen, underscore, and dot; guard against preg_split returning false
            $parts = preg_split('/[\s\-_.]+/', $spaced) ?: [];

            foreach ($parts as $part) {
                if ($part !== '') {
                    $words[] = $part;
                }
            }
        }

        // Step 5 — lowercase every decomposed word
        $words = array_map('strtolower', $words);

        // Step 6 — singularise each word via the injected inflector port;
        // uncountable words bypass singularisation and are returned as-is
        return array_map(
            fn (string $word): string => in_array($word, $uncountables, true)
                ? $word
                : $this->inflector->singular($word),
            $words,
        );
    }
}
