<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest whose rules() throws.
 *
 * Proves the resolver degrades a rules() that reaches for request state to no
 * documented body rather than letting the failure escape.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ThrowingFormRequest extends FormRequest
{
    /**
     * Return the validation rules, always failing.
     *
     * @return never
     *
     * @throws \RuntimeException
     */
    public function rules(): never
    {
        throw new \RuntimeException('rules() reached for request state');
    }
}
