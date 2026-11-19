<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest that only authorizes, declaring no rules().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RulelessFormRequest extends FormRequest
{
    /**
     * Determine whether the user may make the request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }
}
