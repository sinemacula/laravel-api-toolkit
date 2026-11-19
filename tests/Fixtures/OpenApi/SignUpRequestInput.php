<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesFieldExamples;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source giving some of its fields example values.
 *
 * The confirmed password, the patterned code and the list of interests take the
 * author's values, so the generated request example can be proven to repeat the
 * password in its confirmation, to pass the pattern no generated value would,
 * and to use a container key's value as the whole list.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SignUpRequestInput implements DefinesFieldExamples, DefinesInputSchema
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
            'email'            => ['required', 'email'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
            'code'             => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'born_on'          => ['required', 'date_format:d/m/Y'],
            'interests'        => ['array'],
            'interests.*.name' => ['required', 'string'],
        ];
    }

    /**
     * Return the example values keyed by rules key.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function fieldExamples(): array
    {
        return [
            'password'  => 'correct-horse',
            'code'      => 'ABC',
            'interests' => [['name' => 'chess'], ['name' => 'go']],
        ];
    }
}
