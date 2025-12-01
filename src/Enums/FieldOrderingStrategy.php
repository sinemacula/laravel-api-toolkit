<?php

namespace SineMacula\ApiToolkit\Enums;

/**
 * Defines the strategies available for ordering resolved API fields.
 *
 * @author      Michael Stivala <michael.stivala@verifast.app>
 * @copyright   2025 Verifast, Inc.
 */
enum FieldOrderingStrategy: string
{
    /**
     * Order resolved fields into a predictable output structure.
     *
     * Rules:
     *  - "_type" always first
     *  - "id" always second
     *  - any timestamps (*_at) always last
     *  - everything else alphabetized in between
     */
    case DEFAULT = 'default';

    /**
     * Order resolved fields in the order they were requested.
     */
    case BY_REQUESTED_FIELDS = 'by_requested_fields';
}
