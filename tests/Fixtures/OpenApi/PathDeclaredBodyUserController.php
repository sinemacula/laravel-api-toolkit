<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource controller whose every action declares a body other than the
 * resource.
 *
 * The store declares a union beside a request schema, a header, and a thrown
 * exception, the update a single shape and no request body, the show a single
 * shape, and the index and destroy a shape beside a status of their own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class PathDeclaredBodyUserController extends AuthorizedController
{
    /**
     * List widgets, answering with a partial collection.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class, collection: true)]
    #[ResponseStatus(HttpStatus::PARTIAL_CONTENT)]
    public function index(): void {}

    /**
     * Show a widget.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    public function show(): void {}

    /**
     * Sign in, answering with either sign-in shape.
     *
     * @return never
     *
     * @throws \Tests\Fixtures\OpenApi\BurstLimitedException
     */
    #[RequestSchema(ArticleRequestInput::class)]
    #[ResponseHeader('Set-Cookie', 'The refresh token.')]
    #[ResponseSchema([SignedInShape::class, PendingSignInShape::class])]
    public function store(): never
    {
        throw new BurstLimitedException;
    }

    /**
     * Refresh a sign-in.
     *
     * @return void
     */
    #[ResponseSchema(SignedInShape::class)]
    public function update(): void {}

    /**
     * Delete a resource, answering with a widget.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    #[ResponseStatus(HttpStatus::OK)]
    public function destroy(): void {}
}
