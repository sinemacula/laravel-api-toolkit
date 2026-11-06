<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source keying fields by list indices.
 *
 * Declares a top-level field named by an index, an array field constraining its
 * first element by index, and an untyped field whose child is named by an
 * index, so the exported body can be checked for keys PHP casts to integers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NumericKeyRequestInput implements DefinesInputSchema
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<array-key, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [
            '0'      => ['required', 'string'],
            'tags'   => ['required', 'array'],
            'tags.0' => ['required', 'string'],
            'meta.0' => ['required', 'string'],
        ];
    }
}
