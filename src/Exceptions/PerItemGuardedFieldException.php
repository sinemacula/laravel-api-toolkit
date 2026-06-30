<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

/**
 * Thrown when a field whose guard depends on the row is derived into a tabular
 * export schema.
 *
 * A tabular export includes or omits whole columns, never individual cells, so
 * a guard that inspects the row cannot be honoured: every row would share the
 * same column, and a value the guard meant to hide for some rows would leak.
 * The trait refuses such a field at schema-build time rather than expose it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PerItemGuardedFieldException extends \RuntimeException
{
    /**
     * Create the exception for the given field and resource type.
     *
     * @param  string  $field
     * @param  string  $resourceType
     * @return self
     */
    public static function forField(string $field, string $resourceType): self
    {
        return new self(sprintf(
            'The "%s" field on the "%s" resource carries a guard that depends on the row, '
            . 'so it cannot be exported to a tabular format. A tabular export includes or '
            . 'omits whole columns rather than individual cells, so a per-row guard cannot '
            . 'be honoured without leaking the values it is meant to hide. Exclude the field '
            . 'from the export, or override tabular() and gate the column with the exporter\'s '
            . '->visible().',
            $field,
            $resourceType,
        ));
    }
}
