<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\Post;

/**
 * Fixture authorized controller for the posts nested under a user.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Post::class)]
final class UserPostController extends AuthorizedController
{
    /**
     * List the user's posts.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Show one of the user's posts.
     *
     * @return void
     */
    public function show(): void {}
}
