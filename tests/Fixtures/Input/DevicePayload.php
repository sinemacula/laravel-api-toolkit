<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;
use Tests\Fixtures\Enums\Platform;

/**
 * Fixture payload registering a device on a pure-enum platform.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DevicePayload extends Payload
{
    /**
     * Create a new DevicePayload instance.
     *
     * @param  string  $name
     * @param  \Tests\Fixtures\Enums\Platform  $platform
     */
    public function __construct(

        /** The device name */
        public readonly string $name,

        /** The platform, sent by its lowercase case name */
        public readonly Platform $platform,
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
            'name'     => ['required', 'string'],
            'platform' => ['required', new PureEnum(Platform::class)],
        ];
    }
}
