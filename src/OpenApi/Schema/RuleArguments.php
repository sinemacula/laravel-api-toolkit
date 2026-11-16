<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Reads the arguments a field's normalised rule tokens carry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class RuleArguments
{
    /**
     * Read the arguments of every token carrying the named rule.
     *
     * @param  list<string>  $tokens
     * @param  string  $rule
     * @return list<string>
     */
    public function of(array $tokens, string $rule): array
    {
        $arguments = [];

        foreach ($tokens as $token) {
            $parts = explode(':', $token, 2);

            if ($parts[0] !== $rule || !isset($parts[1])) {
                continue;
            }

            $arguments[] = $parts[1];
        }

        return $arguments;
    }
}
