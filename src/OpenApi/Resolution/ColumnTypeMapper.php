<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use SineMacula\ApiToolkit\Services\Introspection\ColumnDefinition;

/**
 * Maps a database column definition (and optional model cast) to a resolved
 * OpenAPI field schema.
 *
 * Applies the inference type-map: a model cast is a stronger signal than the
 * storage type and takes precedence, otherwise the column's driver-normalised
 * type drives the mapping. Any type that cannot be resolved falls through to a
 * permissive schema explicitly flagged undocumented -- the mapper never guesses
 * a confident-but-wrong concrete type.
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
     * @param  \SineMacula\ApiToolkit\Services\Introspection\ColumnDefinition  $column
     * @param  string|null  $cast
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema
     */
    public function map(ColumnDefinition $column, ?string $cast = null): OpenApiFieldSchema
    {
        $resolved = $this->resolveFromCast($cast) ?? $this->resolveFromTypeName($column->typeName);

        if ($resolved === null) {
            return OpenApiFieldSchema::undocumented();
        }

        return new OpenApiFieldSchema(
            type    : $resolved['type'],
            format  : $resolved['format'] ?? null,
            nullable: $column->nullable,
        );
    }

    /**
     * Resolve a schema shape from a model cast, or null when the cast carries
     * no JSON-Schema-relevant mapping.
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
     * Resolve a schema shape from a driver-normalised column type name, or null
     * when the type is unknown.
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
     * Resolve string, integer, number and boolean column types, or null when
     * the type is not a scalar.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveScalarType(string $typeName): ?array
    {
        return match ($typeName) {
            'char', 'varchar', 'text', 'tinytext', 'mediumtext',
            'longtext', 'string', 'enum', 'set' => ['type' => 'string'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            'bigint', 'int', 'integer', 'mediumint', 'smallint',
            'tinyint', 'serial', 'bigserial' => ['type' => 'integer'],
            'decimal', 'numeric', 'float', 'double',
            'real', 'money' => ['type' => 'number'],
            'boolean', 'bool' => ['type' => 'boolean'],
            default => null,
        };
    }

    /**
     * Resolve date, date-time and time column types, or null when the type is
     * not temporal.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveTemporalType(string $typeName): ?array
    {
        return match ($typeName) {
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime', 'timestamp', 'datetimetz',
            'timestamptz' => ['type' => 'string', 'format' => 'date-time'],
            'time', 'timetz' => ['type' => 'string', 'format' => 'time'],
            default => null,
        };
    }

    /**
     * Resolve binary column types, or null when the type is not a binary value.
     *
     * An uncast json/jsonb column is deliberately left unresolved: it can hold
     * an object, scalar or string, and absent a cast Eloquent returns the raw
     * JSON string, so any concrete type here would be a confident-but-wrong
     * guess. It falls through to an undocumented schema instead.
     *
     * @param  string  $typeName
     * @return array{type: string, format?: string}|null
     */
    private function resolveStructuredType(string $typeName): ?array
    {
        return match ($typeName) {
            'binary', 'blob', 'bytea' => ['type' => 'string', 'format' => 'byte'],
            default => null,
        };
    }
}
