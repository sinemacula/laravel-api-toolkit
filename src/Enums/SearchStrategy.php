<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * How a declared column is matched against a free-text search term.
 *
 * Equality and prefix matches ride a B-tree; an anywhere-match needs a
 * substring index.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum SearchStrategy: string
{
    /** Match the whole column value against the term */
    case EXACT = 'exact';

    /** Match column values that begin with the term */
    case PREFIX = 'prefix';

    /** Match column values carrying the term at any position */
    case SUBSTRING = 'substring';

    /**
     * Determine whether the strategy matches by pattern, so wildcards in the
     * term must be escaped.
     *
     * @return bool
     */
    public function matchesByPattern(): bool
    {
        return $this !== self::EXACT;
    }

    /**
     * Wrap an escaped term in the wildcards this strategy matches by.
     *
     * @param  string  $term
     * @return string
     */
    public function wrapWildcards(string $term): string
    {
        return match ($this) {
            self::EXACT     => $term,
            self::PREFIX    => $term . '%',
            self::SUBSTRING => '%' . $term . '%',
        };
    }
}
