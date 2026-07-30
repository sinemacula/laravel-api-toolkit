<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use Illuminate\Validation\Rule;
use SineMacula\ApiToolkit\Services\Input\Payload;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;

/**
 * Concrete Payload fixture used to prove container resolution end to end.
 *
 * A required string, an optional nullable integer, and an optional backed enum
 * exercise the hydration, defaulting, and enum-casting paths a type-hinted
 * controller argument must survive.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StorePayload extends Payload
{
    /**
     * Create a new StorePayload instance.
     *
     * @param  string  $title
     * @param  int|null  $quantity
     * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $status
     */
    public function __construct(

        /** The title; required and validated as a string. */
        public readonly string $title,

        /** The quantity; optional, nullable integer. */
        public readonly ?int $quantity = null,

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
            'title'    => ['required', 'string'],
            'quantity' => ['sometimes', 'nullable', 'integer'],
            'status'   => ['sometimes', 'nullable', Rule::enum(StubStatusEnum::class)],
        ];
    }
}
