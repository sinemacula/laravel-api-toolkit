<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Reads whether a list's item rules make its items unique by JSON equality.
 *
 * JSON-equal scalars are loosely equal, so distinct scalars are unique.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class DistinctItems
{
    /** The item types whose loose equality agrees with JSON equality */
    private const array SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

    /**
     * Determine whether the items' rules make them unique by JSON equality.
     *
     * A strict comparison tells 1 from 1.0, which JSON equality does not.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>|false  $items
     * @return bool
     */
    public function areUnique(array $tokens, array|false $items): bool
    {
        if ($items === false || (!isset($items['$ref']) && !in_array($items['type'] ?? null, self::SCALAR_TYPES, true))) {
            return false;
        }

        foreach ($tokens as $token) {
            $parts = explode(':', $token, 2);

            if ($parts[0] === 'distinct' && !in_array('strict', explode(',', $parts[1] ?? ''), true)) {
                return true;
            }
        }

        return false;
    }
}
