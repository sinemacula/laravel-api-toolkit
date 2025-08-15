<?php

namespace SineMacula\ApiToolkit\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;

/**
 * Base API form request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class FormRequest extends LaravelFormRequest
{
    /**
     * Handle a failed validation attempt.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ApiException
     *
     * @return void
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidInputException($validator->getMessageBag()->toArray());
    }
}
