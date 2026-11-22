<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\ColumnValueKind;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Checks a value against the storage type the catalogue reports for a column,
 * and resolves a date-time to the form the model stores.
 *
 * The accepted forms are canonical API forms, narrower than each engine parses,
 * so every engine reads a value the same way or not at all. A column the
 * catalogue does not describe is unchecked.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ColumnLiteral
{
    /** @var array<string, array{int, int}> The range of each integer type that errors out of range, lowest then highest */
    public const array INTEGER_BOUNDS = [
        'int2' => [-32768, 32767],
        'int4' => [-2147483648, 2147483647],
        'int8' => [PHP_INT_MIN, PHP_INT_MAX],
    ];

    /** @var string A date, then optionally a time with an optional zone, as a portable JSON Schema pattern */
    public const string DATE_TIME_PATTERN = '^([0-9]{4})-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])'
        . '([T ]([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9](\.[0-9]+)?)?(Z|[+-](0[0-9]|1[0-4])(:[0-5][0-9])?)?)?$';

    /**
     * Create a new column literal resolver.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     */
    public function __construct(

        /** Reads the column definitions of the model a value is compared on */
        private SchemaIntrospectionProvider $introspector,
    ) {}

    /**
     * Describe what the column expects in place of the value, or return null
     * when the value fits.
     *
     * A NUL character is refused on every column, as no engine compares it the
     * same way.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @param  bool|float|int|string  $value
     * @return string|null
     */
    public function mismatch(Model $model, string $column, bool|float|int|string $value): ?string
    {
        if (is_string($value) && str_contains($value, "\0")) {
            return Rejection::message('filters.expected.nul');
        }

        $type = $this->typeOf($model, $column);

        if ($type === null || $this->fits(ColumnValueKind::of($type), $type, $value)) {
            return null;
        }

        return $this->expectation(ColumnValueKind::of($type), $type);
    }

    /**
     * Determine whether the value is a cursor boundary the column compares.
     *
     * A date cast is stored as a date-time without a zone where dates are kept
     * as text, which a date column compares as its day.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @param  bool|float|int|string  $value
     * @return bool
     */
    public function fitsBoundary(Model $model, string $column, bool|float|int|string $value): bool
    {
        if (is_string($value) && $this->typeOf($model, $column) === 'date' && preg_match('/ \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $this->isDateTime($value);
        }

        return $this->mismatch($model, $column, $value) === null;
    }

    /**
     * Resolve a fitting value to the literal the column compares.
     *
     * A zoned date-time is converted to the default timezone, which Eloquent
     * reads stored values in; one without a zone is already a wall clock there
     * and is only reformatted.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @param  bool|float|int|string  $value
     * @return bool|float|int|string
     */
    public function resolve(Model $model, string $column, bool|float|int|string $value): bool|float|int|string
    {
        $type = $this->typeOf($model, $column);

        if (!is_string($value) || ColumnValueKind::of($type) !== ColumnValueKind::DATETIME) {
            return $value;
        }

        $format = $model->getDateFormat();
        $zoned  = preg_match('/[OPTUZcepr]/', (string) preg_replace('/\\\./', '', $format)) === 1;

        // An offsetless literal is read in the session's timezone
        if ($type === 'timestamptz' && !$zoned) {
            $format .= 'P';
            $zoned = true;
        }

        $timezone = date_default_timezone_get();

        // Past the time separator, only a zone holds Z, + or -
        if (preg_match('/[T ].*[Z+-]/', $value) === 1) {
            return CarbonImmutable::parse($value)->setTimezone($timezone)->format($format);
        }

        return CarbonImmutable::parse($value, $zoned ? $timezone : 'UTC')->format($format);
    }

    /**
     * Return the catalogue type name of the column, or null when it has none.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @return string|null
     */
    public function typeOf(Model $model, string $column): ?string
    {
        return $this->introspector->getColumnDefinitions($model)[$column]->typeName ?? null;
    }

    /**
     * Determine whether the value is one the given kind of column compares.
     *
     * A boolean is refused outside integer and boolean columns, which bind it
     * as 0 or 1.
     *
     * @param  \SineMacula\ApiToolkit\Enums\ColumnValueKind  $kind
     * @param  string  $type
     * @param  bool|float|int|string  $value
     * @return bool
     */
    private function fits(ColumnValueKind $kind, string $type, bool|float|int|string $value): bool
    {
        return match ($kind) {
            ColumnValueKind::INTEGER  => is_bool($value) || $this->isInteger($type, $value),
            ColumnValueKind::NUMBER   => !is_bool($value) && $this->isNumber($type, $value),
            ColumnValueKind::BOOLEAN  => in_array($value, [true, false, 0, 1, '0', '1'], true),
            ColumnValueKind::DATE     => is_string($value) && $this->isDate($value),
            ColumnValueKind::DATETIME => is_string($value) && $this->isDateTime($value),
            ColumnValueKind::UUID     => is_string($value) && Str::isUuid($value),
            default                   => !is_bool($value),
        };
    }

    /**
     * Determine whether the value is an integer the column holds.
     *
     * The limits are compared as digit strings, so a value past PHP's integer
     * range is still judged exactly.
     *
     * @param  string  $type
     * @param  float|int|string  $value
     * @return bool
     */
    private function isInteger(string $type, float|int|string $value): bool
    {
        if (is_float($value) || preg_match('/^([+-]?)0*(\d+)$/', (string) $value, $parts) !== 1) {
            return false;
        }

        if (!isset(self::INTEGER_BOUNDS[$type])) {
            return true;
        }

        $limit = ltrim((string) self::INTEGER_BOUNDS[$type][$parts[1] === '-' ? 0 : 1], '-');

        return strlen($parts[2]) < strlen($limit) || (strlen($parts[2]) === strlen($limit) && strcmp($parts[2], $limit) <= 0);
    }

    /**
     * Determine whether the value is a number the column holds.
     *
     * A single-precision column rounds the value as the engine would, and a
     * non-zero value that rounds to zero has underflowed.
     *
     * @param  string  $type
     * @param  float|int|string  $value
     * @return bool
     */
    private function isNumber(string $type, float|int|string $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        $number = (float) $value;

        if ($type === 'float4') {
            /** @var array{1: float} $single */
            $single = unpack('g', pack('g', $number));
            $number = $single[1];
        }

        $nonZero = is_string($value) ? preg_match('/^[^eE]*[1-9]/', $value) === 1 : (float) $value !== 0.0;

        return is_finite($number) && ($number !== 0.0 || !$nonZero);
    }

    /**
     * Determine whether the value is a date alone, naming a real day.
     *
     * @param  string  $value
     * @return bool
     */
    private function isDate(string $value): bool
    {
        return strlen($value) === 10 && $this->isDateTime($value);
    }

    /**
     * Determine whether the value is a date or date-time naming a real instant.
     *
     * Checked before parsing, which would roll 2024-02-30 over.
     *
     * @param  string  $value
     * @return bool
     */
    private function isDateTime(string $value): bool
    {
        if (preg_match('/' . self::DATE_TIME_PATTERN . '/D', $value, $parts) !== 1) {
            return false;
        }

        [, $year, $month, $day] = $parts;

        return checkdate((int) $month, (int) $day, (int) $year);
    }

    /**
     * Describe the values the given kind of column compares.
     *
     * @param  \SineMacula\ApiToolkit\Enums\ColumnValueKind  $kind
     * @param  string  $type
     * @return string
     */
    private function expectation(ColumnValueKind $kind, string $type): string
    {
        if (isset(self::INTEGER_BOUNDS[$type])) {
            return Rejection::message('filters.expected.integer_between', ['min' => self::INTEGER_BOUNDS[$type][0], 'max' => self::INTEGER_BOUNDS[$type][1]]);
        }

        return Rejection::message('filters.expected.' . ($type === 'float4' ? 'single' : $kind->value));
    }
}
