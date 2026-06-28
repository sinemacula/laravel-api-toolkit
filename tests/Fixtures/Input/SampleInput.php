<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use Illuminate\Validation\Rule;
use SineMacula\ApiToolkit\Services\Input\InputData;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;

/**
 * Concrete InputData fixture with typed promoted properties and explicit rules.
 *
 * Demonstrates the canonical pattern for typed, immutable service inputs: a
 * final class with readonly promoted properties, a rules() override supplying
 * standard Laravel validation rules, and properties readable by handlers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SampleInput extends InputData
{
    /**
     * Create a new SampleInput instance.
     *
     * @param  string  $city
     * @param  int|null  $age
     * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $status
     */
    public function __construct(

        /** The city name; required and validated as a string. */
        public readonly string $city,

        /** The age; optional, nullable, with an inclusive maximum of 120. */
        public readonly ?int $age = null,

        /** The status; optional, nullable, backed enum. */
        public readonly ?StubStatusEnum $status = null,
    ) {}

    /**
     * Return the Laravel validation rules for this input.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [
            'city'   => ['required', 'string'],
            'age'    => ['sometimes', 'nullable', 'integer', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::enum(StubStatusEnum::class)],
        ];
    }
}
