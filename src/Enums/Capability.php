<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * The access path a filterable column declares, and so which operators it
 * answers.
 *
 * The claim is trusted, not checked against the index catalogue. There is no
 * text case: partial matching belongs to the search surface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Capability: string
{
    /** Equality against a supplied value, served by a B-tree seek */
    case EXACT = 'exact';

    /** A small closed set of values, so the complement of one is bounded */
    case ENUM = 'enum';

    /** An ordered column, so comparison bounds seek a contiguous range */
    case RANGE = 'range';

    /** A JSON document, served by a containment index */
    case DOCUMENT = 'document';

    /** A column with no access path the resource is willing to vouch for */
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
     * Not-equal is limited to the small closed set, since the complement of one
     * value spans nearly the whole index.
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
     * Determine whether the matrix governs the given operator token at all.
     *
     * Application-registered operators are not governed, since the package
     * cannot know which access path they need.
     *
     * @param  string  $token
     * @return bool
     */
    public static function governs(string $token): bool
    {
        foreach (self::cases() as $case) {

            if ($case->permits($token)) {
                return true;
            }
        }

        return false;
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
