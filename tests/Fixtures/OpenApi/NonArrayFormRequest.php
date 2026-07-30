<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest whose rules() yields a non-array value.
 *
 * Proves the resolver degrades a source that does not produce a rules array to
 * no documented body rather than passing the value on to the translator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NonArrayFormRequest extends FormRequest
{
    /**
     * Return a deliberately malformed, non-array rules value.
     *
     * @return string
     */
    public function rules(): string
    {
        return 'not-an-array';
    }
}
