<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source carrying a binary upload field.
 *
 * Declares a required file field so the resolver can be proven to serve the
 * body as multipart form data when any field is binary.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UploadRequestInput implements DefinesInputSchema
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [
            'avatar' => 'required|file',
        ];
    }
}
