<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * The kind of value a column compares, read from its catalogue type name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum ColumnValueKind: string
{
    /** A whole number */
    case INTEGER = 'integer';

    /** A finite number */
    case NUMBER = 'number';

    /** True or false */
    case BOOLEAN = 'boolean';

    /** A calendar day */
    case DATE = 'date';

    /** An instant, a calendar day and a time of it */
    case DATETIME = 'datetime';

    /** A UUID */
    case UUID = 'uuid';

    /** A type the catalogue names but no other kind covers, compared as text */
    case TEXT = 'text';

    /** A column the catalogue does not describe */
    case UNCHECKED = 'unchecked';

    /** @var array<string, self> The kind of each catalogue type name that is not text */
    private const array TYPES = [
        'int2'        => self::INTEGER,
        'int4'        => self::INTEGER,
        'int8'        => self::INTEGER,
        'smallint'    => self::INTEGER,
        'int'         => self::INTEGER,
        'integer'     => self::INTEGER,
        'mediumint'   => self::INTEGER,
        'bigint'      => self::INTEGER,
        'tinyint'     => self::INTEGER,
        'decimal'     => self::NUMBER,
        'numeric'     => self::NUMBER,
        'float'       => self::NUMBER,
        'float8'      => self::NUMBER,
        'double'      => self::NUMBER,
        'real'        => self::NUMBER,
        'float4'      => self::NUMBER,
        'bool'        => self::BOOLEAN,
        'boolean'     => self::BOOLEAN,
        'date'        => self::DATE,
        'datetime'    => self::DATETIME,
        'timestamp'   => self::DATETIME,
        'timestamptz' => self::DATETIME,
        'uuid'        => self::UUID,
    ];

    /**
     * Return the kind of value a column of the catalogue type compares, or
     * unchecked when there is no type.
     *
     * @param  string|null  $type
     * @return self
     */
    public static function of(?string $type): self
    {
        return $type === null ? self::UNCHECKED : (self::TYPES[$type] ?? self::TEXT);
    }
}
