<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest that prepares its input and reads the route for a rule, as a
 * consuming application's update request does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParityProfileFormRequest extends FormRequest
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', Rule::unique('users', 'email')->ignore($this->route('user'))],
        ];
    }

    /**
     * Normalise the email before it is validated.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (!is_string($email)) {
            return;
        }

        $this->merge(['email' => strtolower(trim($email))]);
    }
}
