<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;
use Tests\Fixtures\Enums\Magnitude;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;

/**
 * Payload fixture hydrating backed enum properties without an enum rule.
 *
 * Its rules admit any value, so every value reaches the payload's own enum cast
 * and its canonical-form check alone decides whether it hydrates.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BackedEnumInput extends Payload
{
    /**
     * Create a new BackedEnumInput instance.
     *
     * @param  \Tests\Fixtures\Enums\Magnitude|null  $magnitude
     * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $status
     */
    public function __construct(

        /** An int-backed enum property. */
        public readonly ?Magnitude $magnitude = null,

        /** A string-backed enum property. */
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
            'magnitude' => ['sometimes'],
            'status'    => ['sometimes'],
        ];
    }
}
