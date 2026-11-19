<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Contract for a rules source that gives its fields example values.
 *
 * Each value is exported as the example of the field its rules key names, such
 * as `items.*.sku`; a value at a container key, such as `items` or `address`,
 * is used as that whole subtree. The rest of the request example is generated.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DefinesFieldExamples
{
    /**
     * Return the example values keyed by rules key.
     *
     * @return array<array-key, mixed>
     */
    public static function fieldExamples(): array;
}
