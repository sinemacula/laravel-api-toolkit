<?php

namespace SineMacula\ApiToolkit\Contracts;

/**
 * API resource interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
interface ApiResourceInterface
{
    /**
     * Get the resource type.
     *
     * @return string
     */
    public static function getResourceType(): string;
}
