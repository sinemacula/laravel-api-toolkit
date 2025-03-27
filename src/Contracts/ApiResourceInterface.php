<?php

namespace SineMacula\ApiToolkit\Contracts;

/**
 * API resource interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
interface ApiResourceInterface
{
    /**
     * Get the resource type.
     *
     * @return string
     */
    public static function getResourceType(): string;

    /**
     * Gets the default fields that should be included in the response.
     *
     * @return array
     */
    public static function getDefaultFields(): array;
}
