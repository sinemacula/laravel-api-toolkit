<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\Post;

/**
 * Fixture authorized controller whose model has no registered resource.
 *
 * Used to prove that a route whose model is absent from the resource map is
 * skipped by the path builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Post::class)]
final class PathUnmappedController extends AuthorizedController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Create a resource.
     *
     * @return void
     */
    public function store(): void {}

    /**
     * Delete a resource.
     *
     * @return void
     */
    public function destroy(): void {}
}
