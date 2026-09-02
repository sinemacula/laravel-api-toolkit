<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\Article;

/**
 * Fixture authorized controller reading a model that soft deletes.
 *
 * Exists so the two read actions can be exercised against a model whose rows
 * can be trashed, which is what decides whether soft-delete visibility is
 * offered on an operation at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Article::class)]
final class PathArticleController extends AuthorizedController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Show a single resource.
     *
     * @return void
     */
    public function show(): void {}
}
