<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest whose rules() reads cleanly from a plain instance.
 *
 * Declares a required string and an email so the resolver can be proven to
 * instantiate the FormRequest and document its rules as a JSON body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WorkingFormRequest extends FormRequest
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'  => 'required|string|max:255',
            'email' => 'required|string|email',
        ];
    }
}
