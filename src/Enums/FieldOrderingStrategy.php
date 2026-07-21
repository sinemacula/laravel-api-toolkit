<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Defines the strategies available for ordering resolved API fields.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum FieldOrderingStrategy: string
{
    /** "_type" first, "id" second, others alphabetised, timestamps last. */
    case DEFAULT = 'default';

    /** Order resolved fields in the order they were requested. */
    case BY_REQUESTED_FIELDS = 'by_requested_fields';
}
