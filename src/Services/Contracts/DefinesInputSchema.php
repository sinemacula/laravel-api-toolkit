<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Contracts;

/**
 * Single static source of truth for a request's validation rules.
 *
 * Static so the OpenAPI exporter can read the rules without a request or an
 * instance.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DefinesInputSchema
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<array-key, mixed>
     */
    public static function rules(): array;
}
