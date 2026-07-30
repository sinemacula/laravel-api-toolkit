<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;
use Tests\Fixtures\Input\StorePayload;

/**
 * Fixture non-resource controller exercising request-body discovery.
 *
 * Carries one action per discovery branch: a directive-named source, a
 * type-hinted rules source, a directive winning over a competing type-hint, a
 * binary upload source, a throwing FormRequest, a plainly readable FormRequest,
 * a FormRequest that also declares static rules, a FormRequest yielding a
 * non-array, a non-named-type parameter, and read-only actions that must never
 * carry a body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathRequestBodyController
{
    /**
     * Create a resource from a directive-named rules source.
     *
     * @return void
     */
    #[RequestSchema(ArticleRequestInput::class)]
    public function store(): void {}

    /**
     * Update a resource from a type-hinted rules source.
     *
     * @param  \Tests\Fixtures\Input\StorePayload  $input
     * @return void
     */
    public function update(StorePayload $input): void {}

    /**
     * Create a resource where the directive wins over the type-hinted source.
     *
     * @param  \Tests\Fixtures\Input\StorePayload  $input
     * @return void
     */
    #[RequestSchema(ArticleRequestInput::class)]
    public function both(StorePayload $input): void {}

    /**
     * Create a resource from a binary upload source.
     *
     * @return void
     */
    #[RequestSchema(UploadRequestInput::class)]
    public function upload(): void {}

    /**
     * Create a resource from a FormRequest whose rules() throws.
     *
     * @param  \Tests\Fixtures\OpenApi\ThrowingFormRequest  $request
     * @return void
     */
    public function throwing(ThrowingFormRequest $request): void {}

    /**
     * Update a resource from a plainly readable FormRequest source.
     *
     * @param  \Tests\Fixtures\OpenApi\WorkingFormRequest  $request
     * @return void
     */
    public function formRequest(WorkingFormRequest $request): void {}

    /**
     * Create a resource from a FormRequest that also declares static rules.
     *
     * @param  \Tests\Fixtures\OpenApi\HybridRequestInput  $input
     * @return void
     */
    public function hybrid(HybridRequestInput $input): void {}

    /**
     * Create a resource from a FormRequest whose rules() yields a non-array.
     *
     * @param  \Tests\Fixtures\OpenApi\NonArrayFormRequest  $request
     * @return void
     */
    public function nonArray(NonArrayFormRequest $request): void {}

    /**
     * Create a resource from a parameter whose type is not a single named
     * class, which names no rules source.
     *
     * @param  int|string  $input
     * @return void
     */
    public function unionParam(int|string $input): void {}

    /**
     * List the resources without a request body.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Delete a resource without a request body.
     *
     * @return void
     */
    public function destroy(): void {}
}
