<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture resource controller whose actions declare a resource as their body.
 *
 * The index and show declare their own resource in the shape they answer with,
 * the store its own resource as a collection, and the update another registered
 * resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class PathDeclaredResourceUserController extends AuthorizedController
{
    /**
     * List the users.
     *
     * @return void
     */
    #[ResponseSchema(UserResource::class, collection: true)]
    public function index(): void {}

    /**
     * Show a user.
     *
     * @return void
     */
    #[ResponseSchema(UserResource::class)]
    public function show(): void {}

    /**
     * Create users in bulk, answering with the list created.
     *
     * @return void
     */
    #[ResponseSchema(UserResource::class, collection: true)]
    public function store(): void {}

    /**
     * Update a user, answering with an article.
     *
     * @return void
     */
    #[ResponseSchema(ArticleResource::class)]
    public function update(): void {}
}
