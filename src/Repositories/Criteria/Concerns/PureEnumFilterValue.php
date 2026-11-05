<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Support\PureEnumCast;
use SineMacula\ApiToolkit\Support\PureEnumName;

/**
 * Translates a filter value for a pure-enum column from wire form to storage.
 *
 * A client filters a pure enum by the lowercase name the document lists, while
 * the column holds the case name as declared, so each value is swapped for the
 * name it is stored under before it reaches the operator. A value that names no
 * case, its declared name included, is swapped for one no case can be stored
 * as, so it matches nothing on every database, just as a value of the wrong
 * shape for any other column does. A null keeps its nullity meaning, and every
 * column without a pure-enum cast is left alone.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class PureEnumFilterValue
{
    /** @var string A case name is a non-empty identifier, so nothing stored can equal it */
    private const string UNMATCHED = '';

    /**
     * Resolve the value the given column is filtered by on the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @param  mixed  $value
     * @return mixed
     *
     * @throws \LogicException
     */
    public static function resolve(Model $model, string $column, mixed $value): mixed
    {
        $enum = PureEnumCast::enumOf($model->getCasts()[$column] ?? null);

        if ($enum === null) {
            return $value;
        }

        return is_array($value)
            ? array_map(static fn (mixed $item): ?string => self::storedName($enum, $item), $value)
            : self::storedName($enum, $value);
    }

    /**
     * Return the name the case the value names is stored under.
     *
     * @param  class-string<\UnitEnum>  $enum
     * @param  mixed  $value
     * @return string|null
     *
     * @throws \LogicException
     */
    private static function storedName(string $enum, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $case = is_string($value) ? PureEnumName::tryFrom($enum, $value) : null;

        return $case->name ?? self::UNMATCHED;
    }
}
