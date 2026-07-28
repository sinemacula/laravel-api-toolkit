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
 * binary upload source, a throwing FormRequest, and read-only actions that must
 * never carry a body.
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
