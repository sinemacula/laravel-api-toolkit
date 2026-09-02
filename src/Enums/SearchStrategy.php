<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Describes how a declared column is matched against a free-text search term.
 *
 * Each case names a match shape and, with it, the index a connection has to
 * carry to serve that shape without scanning: an equality comparison and a
 * prefix match both ride a plain B-tree, while an anywhere-match needs an index
 * built for substrings. Declaring the shape is what lets a driver refuse a
 * column it cannot serve from an index instead of quietly emitting a scan.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum SearchStrategy: string
{
    /** Match the whole column value against the term. */
    case EXACT = 'exact';

    /** Match column values that begin with the term. */
    case PREFIX = 'prefix';

    /** Match column values carrying the term at any position. */
    case SUBSTRING = 'substring';

    /**
     * Determine whether the strategy needs an index beyond a plain B-tree.
     *
     * @return bool
     */
    public function requiresSpecialisedIndex(): bool
    {
        return $this === self::SUBSTRING;
    }

    /**
     * Determine whether the strategy matches by pattern rather than by
     * equality, so wildcards carried by the term have to be escaped before it
     * is bound.
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
