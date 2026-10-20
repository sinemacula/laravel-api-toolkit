<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Names a callable list carried by a compiled schema definition.
 *
 * Every compiled field, count and aggregate definition carries a guard list and
 * a transformer list; a case selects one of them and supplies the label used
 * when an entry in that list is reported.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum CallableList
{
    /** The guard callables deciding whether a value is emitted. */
    case GUARDS;

    /** The transformer callables reshaping an emitted value. */
    case TRANSFORMERS;

    /**
     * Return the human-readable label for a single entry in the list.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::GUARDS       => 'Guard',
            self::TRANSFORMERS => 'Transformer',
        };
    }

    /**
     * Select this list from a definition's guard and transformer lists.
     *
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @return array<int, callable(mixed, mixed): mixed>
     */
    public function select(array $guards, array $transformers): array
    {
        return match ($this) {
            self::GUARDS       => $guards,
            self::TRANSFORMERS => $transformers,
        };
    }
}
