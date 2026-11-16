<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

use Illuminate\Support\Str;

/**
 * The SQL function a relation aggregate applies over its related rows.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum AggregateFunction: string
{
    /** The number of related rows */
    case COUNT = 'count';

    /** Whether any related row exists */
    case EXISTS = 'exists';

    /** The sum of a related column */
    case SUM = 'sum';

    /** The average of a related column */
    case AVG = 'avg';

    /** The smallest value of a related column */
    case MIN = 'min';

    /** The largest value of a related column */
    case MAX = 'max';

    /**
     * Determine whether the function aggregates a named related column.
     *
     * @return bool
     */
    public function requiresColumn(): bool
    {
        return $this !== self::COUNT && $this !== self::EXISTS;
    }

    /**
     * Build the key Laravel would alias the aggregate under by default.
     *
     * @param  string  $relation
     * @param  string|null  $column
     * @return string
     */
    public function defaultKey(string $relation, ?string $column): string
    {
        $phrase = sprintf('%s %s %s', $relation, $this->value, strtolower($column ?? '*'));

        return Str::snake((string) preg_replace('/[^[:alnum:][:space:]_]/u', '', $phrase));
    }
}
