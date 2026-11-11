<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * The requested visibility of soft-deleted records.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum TrashedState
{
    /** Exclude soft-deleted records - the default Eloquent scope */
    case NONE;

    /** Include soft-deleted records alongside live records */
    case WITH;

    /** Return only soft-deleted records */
    case ONLY;

    /**
     * Resolve the trashed state from the raw query parameter value.
     *
     * Anything unrecognised excludes soft-deleted records.
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
