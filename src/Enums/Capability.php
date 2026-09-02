<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Describes what a declared filterable column can be asked, and so which filter
 * operators it answers.
 *
 * A bare filterable declaration says only that a column may be queried, never
 * how, so every operator reaches every column and the surface accepts
 * predicates no index can serve. Each case names the access path the column is
 * declared to have and, with it, the operators that path answers without
 * scanning: an equality seek, a small closed set, an ordered range, or a
 * document behind a containment index. The case that vouches for nothing is
 * held to the single narrowest predicate rather than refused outright, so a
 * column whose access path is unknown can be declared honestly instead of being
 * over-claimed to stay queryable.
 *
 * There is deliberately no text case. Matching part of a value belongs to the
 * search surface, where a driver emits the SQL its own engine indexes and
 * refuses a column no index backs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Capability: string
{
    /** Equality against a supplied value, served by a B-tree seek. */
    case EXACT = 'exact';

    /** A small closed set of values, so the complement of one is bounded. */
    case ENUM = 'enum';

    /** An ordered column, so comparison bounds seek a contiguous range. */
    case RANGE = 'range';

    /** A JSON document, served by a containment index. */
    case DOCUMENT = 'document';

    /** A column with no access path the resource is willing to vouch for. */
    case OPAQUE = 'opaque';

    /** @var array<int, string> The tokens a keyed seek answers, one value or a bounded list of them */
    private const array EQUALITY = ['$eq', '$in'];

    /** @var array<int, string> The tokens an ordered column answers beyond equality */
    private const array ORDERING = ['$gt', '$ge', '$lt', '$le', '$between'];

    /** @var array<int, string> The tokens reading the null or the non-null partition of an index */
    private const array NULLITY = ['$null', '$notNull'];

    /**
     * Return the filter operator tokens this capability permits.
     *
     * The nullity pair travels with every case carrying a B-tree, since both
     * halves read one contiguous partition of it. Not-equal does not: the
     * complement of a single value spans nearly the whole index, and is bounded
     * only where the value domain is small and closed, which is the one case
     * declaring that it is. Containment reaches the document case alone, whose
     * column is the only one an inverted index backs. The list fan-out is
     * withheld from the case that promises no index, where each item would cost
     * a scan of its own rather than a seek.
     *
     * @return array<int, string>
     */
    public function permittedOperators(): array
    {
        return match ($this) {
            self::EXACT    => [...self::EQUALITY, ...self::NULLITY],
            self::ENUM     => [...self::EQUALITY, '$neq', ...self::NULLITY],
            self::RANGE    => [...self::EQUALITY, ...self::ORDERING, ...self::NULLITY],
            self::DOCUMENT => ['$contains'],
            self::OPAQUE   => ['$eq'],
        };
    }

    /**
     * Determine whether this capability permits the given operator token.
     *
     * @param  string  $token
     * @return bool
     */
    public function permits(string $token): bool
    {
        return in_array($token, $this->permittedOperators(), true);
    }
}
