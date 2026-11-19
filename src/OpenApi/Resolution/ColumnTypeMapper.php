<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\AsUri;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Maps a column definition and optional model cast to the OpenAPI schema of the
 * value Eloquent returns for it.
 *
 * A present cast decides, though the column may add a format to a cast of the
 * same type; a value with no fixed shape is flagged undocumented, never
 * guessed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ColumnTypeMapper
{
    /** Cast names and the schema type of the value each returns */
    private const array SCALAR_CASTS = [
        'bool'      => 'boolean',
        'boolean'   => 'boolean',
        'int'       => 'integer',
        'integer'   => 'integer',
        'timestamp' => 'integer',
        'float'     => 'number',
        'double'    => 'number',
        'real'      => 'number',
        'string'    => 'string',
        'encrypted' => 'string',
        'hashed'    => 'string',
    ];

    /** Cast names, bare or before a format, that return a Carbon instance */
    private const array DATE_CASTS = ['date', 'datetime', 'immutable_date', 'immutable_datetime'];

    /** Cast classes whose value serialises to a string */
    private const array STRING_CLASS_CASTS = [AsStringable::class, AsUri::class];

    /**
     * Constructor.
     *
     * @param  bool  $fetchesNativeFloats
     */
    public function __construct(

        /** Whether PostgreSQL float4 and float8 columns fetch as floats, as they do from PHP 8.4 */
        private bool $fetchesNativeFloats = PHP_VERSION_ID >= 80400,
    ) {}

    /**
     * Map a column definition (with an optional cast) to a resolved schema.
     *
     * The column is null without a live database, leaving the cast alone and a
     * non-nullable schema.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition|null  $column
     * @param  string|null  $cast
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    public function map(?ColumnDefinition $column, ?string $cast = null): OpenApiFieldSchema
    {
        $fromColumn = $column !== null ? $this->resolveFromTypeName($column->typeName) : null;
        $fromCast   = $cast   !== null ? $this->resolveFromCast($cast) : null;
        $resolved   = $cast === null || $this->refines($fromColumn, $fromCast) ? $fromColumn : $fromCast;

        if ($resolved === null) {
            return OpenApiFieldSchema::undocumented();
        }

        return new OpenApiFieldSchema(
            type    : $resolved['type'],
            format  : $resolved['format'] ?? null,
            nullable: $column->nullable   ?? false,
        );
    }

    /**
     * Determine whether the column's shape refines a formatless cast of the
     * same type, as a uuid column does a string cast.
     *
     * @param  array{type: string, format?: string}|null  $column
     * @param  array{type: string, format?: string}|null  $cast
     * @return bool
     */
    private function refines(?array $column, ?array $cast): bool
    {
        return $column !== null
            && $cast   !== null
            && !isset($cast['format'])
            && $column['type'] === $cast['type'];
    }

    /**
     * Resolve a schema shape from a model cast, read as Eloquent reads it: a
     * bare name in any case and trimmed, a date or decimal argument after a
     * lower-case prefix.
     *
     * @param  string  $cast
     * @return array{type: string, format?: string}|null
     */
    private function resolveFromCast(string $cast): ?array
    {
        $normalised = trim(strtolower($cast));

        return match (true) {
            isset(self::SCALAR_CASTS[$normalised]) => ['type' => self::SCALAR_CASTS[$normalised]],
            in_array($normalised, self::DATE_CASTS, true),
            in_array(strstr($cast, ':', true), self::DATE_CASTS, true) => ['type' => 'string', 'format' => 'date-time'],
            str_starts_with($cast, 'decimal:'),
            in_array($cast, self::STRING_CLASS_CASTS, true) => ['type' => 'string'],
            default                                         => null,
        };
    }

    /**
     * Resolve a schema shape from a driver-normalised column type name.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveFromTypeName(string $typeName): ?array
    {
        $normalised = strtolower($typeName);

        return $this->resolveTextType($normalised) ?? $this->resolveNumericType($normalised);
    }

    /**
     * Resolve the column types fetched as strings.
     *
     * A date-time or time is the driver's own string, which no format
     * describes.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveTextType(string $typeName): ?array
    {
        return match ($typeName) {
            'char', 'bpchar', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext',
            'enum', 'set', 'money', 'datetime', 'timestamp', 'timestamptz',
            'time', 'timetz' => ['type' => 'string'],
            'uuid'           => ['type' => 'string', 'format' => 'uuid'],
            'date'           => ['type' => 'string', 'format' => 'date'],
            default          => null,
        };
    }

    /**
     * Resolve the column types fetched as integers, floats or booleans.
     *
     * PostgreSQL's float4 and float8 fetch as numeric strings before PHP 8.4.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveNumericType(string $typeName): ?array
    {
        return match ($typeName) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer',
            'bigint', 'int2', 'int4', 'int8' => ['type' => 'integer'],
            'float', 'double', 'real'        => ['type' => 'number'],
            'float4', 'float8'               => ['type' => $this->fetchesNativeFloats ? 'number' : 'string'],
            'boolean', 'bool'                => ['type' => 'boolean'],
            default                          => null,
        };
    }
}
