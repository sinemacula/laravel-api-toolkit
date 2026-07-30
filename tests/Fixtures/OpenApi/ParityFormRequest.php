<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest materialising the canonical shared rule set for flow parity.
 *
 * Returns the same rule set the plain and Payload parity fixtures declare, read
 * through a plainly constructed instance, so the resolver can be proven to
 * document a byte-identical body from the FormRequest discovery path.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParityFormRequest extends FormRequest
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ParityInput::rules();
    }
}
