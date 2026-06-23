<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Introspection;

/**
 * Immutable description of a single database column's type and nullability.
 *
 * Returned by the schema introspection port to supply the inference tier of
 * the OpenAPI exporter with a column's driver-normalised base type and whether
 * it admits null. Read only by the emission path; never consulted during
 * runtime serialization.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ColumnDefinition
{
    /**
     * Create a new column definition.
     *
     * @param  string  $name
     * @param  string  $typeName
     * @param  bool  $nullable
     */
    public function __construct(

        /** The column name */
        public string $name,

        /** The driver-normalised base type, e.g. 'varchar', 'bigint', 'json' */
        public string $typeName,

        /** Whether the column admits null */
        public bool $nullable,
    ) {}
}
