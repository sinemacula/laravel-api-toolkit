<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source naming every field by a consecutive list index.
 *
 * Its translated properties are keyed from zero like a PHP list, so the
 * exported body can be checked for an object rather than a JSON array.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class IndexedRequestInput implements DefinesInputSchema
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
            '0' => ['required', 'string'],
            '1' => ['integer'],
        ];
    }
}
