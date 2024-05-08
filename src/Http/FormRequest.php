<?php

namespace SineMacula\ApiToolkit\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionType;

/**
 * Base API form request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
abstract class FormRequest extends LaravelFormRequest
{
    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(ApiExceptionType::INVALID_INPUT, $validator->getMessageBag()->toArray());
    }
}
