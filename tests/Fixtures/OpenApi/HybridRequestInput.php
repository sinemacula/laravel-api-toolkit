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
 * FormRequest. The two paths deliberately diverge: the static rules describe a
 * headline, while constructing the FormRequest throws, so reading it through
 * the instance path would surface no body at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class HybridRequestInput extends FormRequest implements DefinesInputSchema
{
    /**
     * Fail any attempt to construct the FormRequest.
     *
     * @throws \RuntimeException
     */
    public function __construct() // @phpstan-ignore constructor.missingParentCall
    {
        throw new \RuntimeException('The hybrid source must be read statically, never instantiated.');
    }

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
