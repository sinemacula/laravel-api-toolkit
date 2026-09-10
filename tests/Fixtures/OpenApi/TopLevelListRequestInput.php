<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source describing a top-level list payload.
 *
 * Validates the request body as a bare array of strings rather than an object
 * of named fields, so its translated schema carries items and no properties.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TopLevelListRequestInput implements DefinesInputSchema
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return ['*' => 'required|string'];
    }
}
