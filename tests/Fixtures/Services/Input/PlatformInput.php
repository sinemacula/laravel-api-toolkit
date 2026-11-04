<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;
use Tests\Fixtures\Enums\Platform;

/**
 * Payload fixture hydrating pure enum properties from snake_case body keys.
 *
 * The primary platform is validated with the pure enum rule; the fallback
 * platform is validated only as a string, so an unknown value reaches the
 * payload's own enum cast.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PlatformInput extends Payload
{
    /**
     * Create a new PlatformInput instance.
     *
     * @param  \Tests\Fixtures\Enums\Platform|null  $primaryPlatform
     * @param  \Tests\Fixtures\Enums\Platform|null  $fallbackPlatform
     */
    public function __construct(

        /** The platform read from the primary_platform body key. */
        public readonly ?Platform $primaryPlatform = null,

        /** The platform read from the fallback_platform body key. */
        public readonly ?Platform $fallbackPlatform = null,
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
            'primary_platform'  => ['sometimes', 'nullable', new PureEnum(Platform::class)],
            'fallback_platform' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
