<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;

/**
 * Fixture controller whose bodies the HTTP pipeline shapes beyond their rules:
 * a FormRequest preparing its input against a route-dependent rule, and a
 * multipart upload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParityProfileController
{
    /**
     * Update the profile of the routed user.
     *
     * @param  \Tests\Fixtures\OpenApi\ParityProfileFormRequest  $request
     * @return void
     */
    public function update(ParityProfileFormRequest $request): void {}

    /**
     * Upload the avatar of the routed user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    #[RequestSchema(UploadRequestInput::class)]
    public function avatar(Request $request): void
    {
        $request->validate(UploadRequestInput::rules());
    }
}
