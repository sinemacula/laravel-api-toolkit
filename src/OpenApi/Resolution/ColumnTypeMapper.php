<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Maps a column definition and optional model cast to an OpenAPI field schema.
 *
 * A cast wins over the column type; an unresolved type is flagged undocumented,
 * never guessed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ColumnTypeMapper
{
    /** Cast names that resolve to a boolean schema */
    private const array BOOLEAN_CASTS = ['bool', 'boolean'];

    /** Cast names that resolve to a date-time schema */
    private const array DATE_TIME_CASTS = ['datetime', 'immutable_datetime', 'timestamp'];

    /** Cast names that resolve to a date schema */
    private const array DATE_CASTS = ['date', 'immutable_date'];

    /** Cast names that resolve to an array schema */
    private const array ARRAY_CASTS = ['array', 'json', 'collection', 'encrypted:array', 'encrypted:collection', 'asarrayobject', 'ascollection'];

    /** Cast names that resolve to an object schema */
    private const array OBJECT_CASTS = ['object'];

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
        $resolved = $this->resolveFromCast($cast)
            ?? ($column !== null ? $this->resolveFromTypeName($column->typeName) : null);

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
     * Resolve a schema shape from a model cast.
     *
     * @param  string|null  $cast
     * @return array{type: string, format?: string}|null
     */
    private function resolveFromCast(?string $cast): ?array
    {
        if ($cast === null) {
            return null;
        }

        $normalised = strtolower($cast);
        $base       = strtok($normalised, ':') ?: $normalised;

        return match (true) {
            in_array($normalised, self::BOOLEAN_CASTS, true) => ['type' => 'boolean'],
            in_array($base, self::DATE_TIME_CASTS, true)     => ['type' => 'string', 'format' => 'date-time'],
            in_array($base, self::DATE_CASTS, true)          => ['type' => 'string', 'format' => 'date'],
            in_array($normalised, self::ARRAY_CASTS, true)
                || in_array($base, self::ARRAY_CASTS, true) => ['type' => 'array'],
            in_array($base, self::OBJECT_CASTS, true)       => ['type' => 'object'],
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

        return $this->resolveScalarType($normalised)
            ?? $this->resolveTemporalType($normalised)
            ?? $this->resolveStructuredType($normalised);
    }

    /**
     * Resolve string, integer, number and boolean column types.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveScalarType(string $typeName): ?array
    {
        return match ($typeName) {
            'char', 'varchar', 'text', 'tinytext', 'mediumtext',
            'longtext', 'string', 'enum', 'set' => ['type' => 'string'],
            'uuid'                              => ['type' => 'string', 'format' => 'uuid'],
            'bigint', 'int', 'integer', 'mediumint', 'smallint',
            'tinyint', 'serial', 'bigserial' => ['type' => 'integer'],
            'decimal', 'numeric', 'float', 'double',
            'real', 'money'   => ['type' => 'number'],
            'boolean', 'bool' => ['type' => 'boolean'],
            default           => null,
        };
    }

    /**
     * Resolve date, date-time and time column types.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveTemporalType(string $typeName): ?array
    {
        return match ($typeName) {
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime', 'timestamp', 'datetimetz',
            'timestamptz'    => ['type' => 'string', 'format' => 'date-time'],
            'time', 'timetz' => ['type' => 'string', 'format' => 'time'],
            default          => null,
        };
    }

    /**
     * Resolve binary column types.
     *
     * An uncast json column is left unresolved, since Eloquent returns it as a
     * raw string.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveStructuredType(string $typeName): ?array
    {
        return match ($typeName) {
            'binary', 'blob', 'bytea' => ['type' => 'string', 'format' => 'byte'],
            default                   => null,
        };
    }
}
