<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;

/**
 * Fixture controller materialising the shared rule set through every source.
 *
 * Carries one action per request-body discovery path over the same rule set: a
 * type-hinted plain self-describing input, a type-hinted Payload, a type-hinted
 * FormRequest, and a directive naming the plain input. The resolver must
 * document a byte-identical body from each, which is the flow-parity oracle.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParityRequestBodyController
{
    /**
     * Create a resource from a type-hinted plain self-describing input.
     *
     * @param  \Tests\Fixtures\OpenApi\ParityInput  $input
     * @return void
     */
    public function plain(ParityInput $input): void {}

    /**
     * Create a resource from a type-hinted Payload.
     *
     * @param  \Tests\Fixtures\OpenApi\ParityPayload  $input
     * @return void
     */
    public function payload(ParityPayload $input): void {}

    /**
     * Create a resource from a type-hinted FormRequest.
     *
     * @param  \Tests\Fixtures\OpenApi\ParityFormRequest  $request
     * @return void
     */
    public function formRequest(ParityFormRequest $request): void {}

    /**
     * Create a resource from a directive naming the plain self-describing
     * input.
     *
     * @return void
     */
    #[RequestSchema(ParityInput::class)]
    public function attribute(): void {}
}
