<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Defines the strategies available for ordering resolved API fields.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum FieldOrderingStrategy
{
    /** "_type" first, "id" second, others alphabetised, timestamps last. */
    case DEFAULT;

    /** Order resolved fields in the order they were requested. */
    case BY_REQUESTED_FIELDS;
}
