<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture FormRequest that also declares its rules statically.
 *
 * Satisfies both rules-source contracts so the resolver can be proven to read
 * it through the static self-describing path in preference to instantiating the
 * FormRequest.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class HybridRequestInput extends FormRequest implements DefinesInputSchema
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
            'headline' => 'required|string',
        ];
    }
}
