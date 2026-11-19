<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Foundation\Http\FormRequest;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesFieldExamples;

/**
 * Fixture FormRequest giving one of its fields an example value.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExampledFormRequest extends FormRequest implements DefinesFieldExamples
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return (new WorkingFormRequest)->rules();
    }

    /**
     * Return the example values keyed by rules key.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function fieldExamples(): array
    {
        return ['name' => 'Ada Lovelace'];
    }
}
