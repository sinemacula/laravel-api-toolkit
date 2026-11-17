<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
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
    /** @var array<string, string> The kind of value each storage type compares, by catalogue type name */
    private const array KINDS = [
        'int2'        => 'integer',
        'int4'        => 'integer',
        'int8'        => 'integer',
        'smallint'    => 'integer',
        'int'         => 'integer',
        'integer'     => 'integer',
        'mediumint'   => 'integer',
        'bigint'      => 'integer',
        'tinyint'     => 'integer',
        'decimal'     => 'number',
        'numeric'     => 'number',
        'float'       => 'number',
        'float8'      => 'number',
        'double'      => 'number',
        'real'        => 'number',
        'float4'      => 'number',
        'bool'        => 'boolean',
        'boolean'     => 'boolean',
        'date'        => 'date',
        'datetime'    => 'datetime',
        'timestamp'   => 'datetime',
        'timestamptz' => 'datetime',
        'uuid'        => 'uuid',
    ];

    /** @var array<string, array{string, string}> The magnitude limits of the integer types that error out of range, negative then positive */
    private const array INTEGER_LIMITS = [
        'int2' => ['32768', '32767'],
        'int4' => ['2147483648', '2147483647'],
        'int8' => ['9223372036854775808', '9223372036854775807'],
    ];

    /** @var string A date, then optionally a time with an optional zone */
    private const string DATE_TIME = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})'
        . '(?:[T ](?<hour>\d{2}):(?<minute>\d{2})(?::(?<second>\d{2})(?:\.\d+)?)?'
        . '(?<zone>Z|[+-](?<offsetHour>\d{2})(?::(?<offsetMinute>\d{2}))?)?)?$/';

    /** @var array<string, int> The highest value each time part of a date-time may take */
    private const array TIME_LIMITS = ['hour' => 23, 'minute' => 59, 'second' => 59, 'offsetHour' => 14, 'offsetMinute' => 59];

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

        if ($type === null || $this->fits(self::KINDS[$type] ?? 'text', $type, $value)) {
            return null;
        }

        return $this->expectation(self::KINDS[$type] ?? 'text', $type);
    }

    /**
     * Determine whether the value is a cursor boundary the column compares.
     *
     * The paginator writes a date cast as a date-time without a zone, which a
     * date column compares as its day.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @param  bool|float|int|string  $value
     * @return bool
     */
    public function fitsBoundary(Model $model, string $column, bool|float|int|string $value): bool
    {
        if (is_string($value) && $this->typeOf($model, $column) === 'date' && preg_match('/ \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $this->dateTimeParts($value) !== null;
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

        if (!is_string($value) || $type === null || (self::KINDS[$type] ?? null) !== 'datetime') {
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

        if (($this->dateTimeParts($value)['zone'] ?? null) !== null) {
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
    private function typeOf(Model $model, string $column): ?string
    {
        return $this->introspector->getColumnDefinitions($model)[$column]->typeName ?? null;
    }

    /**
     * Determine whether the value is one the given kind of column compares.
     *
     * A boolean is refused outside integer and boolean columns, which bind it
     * as 0 or 1.
     *
     * @param  string  $kind
     * @param  string  $type
     * @param  bool|float|int|string  $value
     * @return bool
     */
    private function fits(string $kind, string $type, bool|float|int|string $value): bool
    {
        return match ($kind) {
            'integer'  => is_bool($value) || $this->isInteger($type, $value),
            'number'   => !is_bool($value) && $this->isNumber($type, $value),
            'boolean'  => in_array($value, [true, false, 0, 1, '0', '1'], true),
            'date'     => is_string($value) && $this->isDate($value),
            'datetime' => is_string($value) && $this->dateTimeParts($value) !== null,
            'uuid'     => is_string($value) && Str::isUuid($value),
            default    => !is_bool($value),
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

        if (!isset(self::INTEGER_LIMITS[$type])) {
            return true;
        }

        $limit = self::INTEGER_LIMITS[$type][$parts[1] === '-' ? 0 : 1];

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
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && $this->dateTimeParts($value) !== null;
    }

    /**
     * Return the parts of a date or date-time naming a real instant, or null
     * when the value is not one.
     *
     * Checked before parsing, which would roll 2024-02-30 and 24:00 over.
     *
     * @param  string  $value
     * @return array<array-key, string|null>|null
     */
    private function dateTimeParts(string $value): ?array
    {
        if (preg_match(self::DATE_TIME, $value, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        foreach (self::TIME_LIMITS as $part => $limit) {
            if ((int) $parts[$part] > $limit) {
                return null;
            }
        }

        return checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year']) ? $parts : null;
    }

    /**
     * Describe the values the given kind of column compares.
     *
     * @param  string  $kind
     * @param  string  $type
     * @return string
     */
    private function expectation(string $kind, string $type): string
    {
        if (isset(self::INTEGER_LIMITS[$type])) {
            return Rejection::message('filters.expected.integer_between', ['min' => '-' . self::INTEGER_LIMITS[$type][0], 'max' => self::INTEGER_LIMITS[$type][1]]);
        }

        return Rejection::message('filters.expected.' . ($type === 'float4' ? 'single' : $kind));
    }
}
