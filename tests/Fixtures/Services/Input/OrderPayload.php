<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;
use SineMacula\ApiToolkit\Services\Input\Payload;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;

/**
 * Payload fixture carrying a backed enum, a pure enum and a date.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OrderPayload extends Payload
{
    /**
     * Create a new OrderPayload instance.
     *
     * @param  string  $reference
     * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum  $status
     * @param  \Tests\Fixtures\Enums\Platform  $platform
     * @param  \Carbon\CarbonInterface  $due
     */
    public function __construct(

        /** The order reference, which names its lock. */
        public readonly string $reference,

        /** A string-backed enum. */
        public readonly StubStatusEnum $status,

        /** A pure enum. */
        public readonly Platform $platform,

        /** A date. */
        public readonly CarbonInterface $due,
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
            'reference' => ['required', 'string'],
            'status'    => ['required', Rule::enum(StubStatusEnum::class)],
            'platform'  => ['required', new PureEnum(Platform::class)],
            'due'       => ['required', 'date'],
        ];
    }
}
