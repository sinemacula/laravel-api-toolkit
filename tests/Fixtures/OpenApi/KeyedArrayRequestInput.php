<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source addressing array fields by named keys.
 *
 * Declares an array field with an optional named child, one with a required
 * named child, one restricted to named keys, and a list with a named child, so
 * the exported body can be checked for the object and list forms the validator
 * accepts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class KeyedArrayRequestInput implements DefinesInputSchema
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
            'tags'         => ['required', 'array'],
            'tags.name'    => ['sometimes', 'string'],
            'address'      => ['array'],
            'address.city' => ['required', 'string'],
            'labels'       => ['nullable', 'array:primary,secondary'],
            'items'        => ['list'],
            'items.name'   => ['sometimes', 'string'],
        ];
    }
}
