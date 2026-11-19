<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesFieldExamples;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Fixture rules source giving a patterned field an example its pattern refuses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MisexampledRequestInput implements DefinesFieldExamples, DefinesInputSchema
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return PatternedRequestInput::rules();
    }

    /**
     * Return the example values keyed by rules key.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function fieldExamples(): array
    {
        return ['code' => 'abc'];
    }
}
