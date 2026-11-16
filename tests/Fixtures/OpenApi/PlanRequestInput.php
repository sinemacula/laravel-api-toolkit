<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Validation\Rule;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;
use Tests\Fixtures\Enums\SelfDescribingPlan;

/**
 * Fixture rules source validating a field against the self-describing plan.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PlanRequestInput implements DefinesInputSchema
{
    /**
     * Return the Laravel validation rules for the request.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return ['plan' => ['required', Rule::enum(SelfDescribingPlan::class)]];
    }
}
