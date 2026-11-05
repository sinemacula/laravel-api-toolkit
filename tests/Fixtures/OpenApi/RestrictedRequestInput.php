<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source refusing some fields and leaving others unconstrained.
 *
 * Declares a prohibited field that another rule also requires, fields
 * prohibited only conditionally, a field validated only by a rule with no
 * schema translation, and an array whose items carry no constraint, so the
 * exported body can be checked for the false schema and for empty schemas
 * encoded as objects.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RestrictedRequestInput implements DefinesInputSchema
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
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'email'    => ['required', 'prohibited'],
            'role'     => ['required', 'string', 'prohibited_if:name,root'],
            'nickname' => ['string', 'prohibited_unless:name,guest'],
            'terms'    => ['accepted'],
            'labels'   => ['array'],
            'labels.*' => ['nullable'],
        ];
    }
}
