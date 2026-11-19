<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest whose rules() takes a dependency the container injects,
 * as Laravel resolves it when validating a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InjectedRulesFormRequest extends FormRequest
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @param  \Illuminate\Contracts\Config\Repository  $config
     * @return array<string, mixed>
     */
    public function rules(Repository $config): array
    {
        return [
            'nickname' => $config->has('fixtures.optional_nickname') ? 'nullable|string' : 'required|string',
        ];
    }
}
