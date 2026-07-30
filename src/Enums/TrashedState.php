<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Describes the requested visibility of soft-deleted records.
 *
 * Maps the raw `trashed` query parameter onto the three mutually exclusive
 * scopes the criteria can apply: exclude soft-deleted rows (the default),
 * include them alongside live rows, or return only the soft-deleted rows.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum TrashedState
{
    /** Exclude soft-deleted records - the default Eloquent scope. */
    case NONE;

    /** Include soft-deleted records alongside live records. */
    case WITH;

    /** Return only soft-deleted records. */
    case ONLY;

    /**
     * Resolve the trashed state from the raw query parameter value.
     *
     * The value is expected to arrive already trimmed and lowercased, but the
     * comparison is defensive so any surrounding whitespace or casing still
     * resolves. Anything unrecognised - including null and empty - falls back
     * to excluding soft-deleted records.
     *
     * @param  string|null  $value
     * @return self
     */
    public static function fromParameter(?string $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'with'  => self::WITH,
            'only'  => self::ONLY,
            default => self::NONE,
        };
    }
}
