<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Fixture rules source spanning the documented request-body rule families.
 *
 * Declares a required bounded string, a nullable string, an array with typed
 * items, an enum-object field, two value-list object fields, an email field,
 * and a confirmed password so the translator can be exercised against presence,
 * size, nesting, enum, format, and cross-field rules through one representative
 * source.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ArticleRequestInput implements DefinesInputSchema
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
            'title'      => 'required|string|max:255',
            'summary'    => 'nullable|string',
            'tags'       => ['array'],
            'tags.*'     => 'string',
            'status'     => [Rule::enum(UserStatus::class)],
            'visibility' => ['string', Rule::in(['draft', 'published'])],
            'level'      => ['integer', Rule::in(array_column(UserLevel::cases(), 'value'))],
            'email'      => 'required|string|email',
            'password'   => ['required', Password::defaults(), 'confirmed'],
        ];
    }
}
