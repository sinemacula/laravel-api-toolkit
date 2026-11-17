<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\Article;

/**
 * Fixture authorized controller over a soft-deleting model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Article::class)]
final class ArticleController extends AuthorizedController
{
    /**
     * Restore a trashed article. Guarded by the restore ability.
     *
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Http\JsonResponse
     */
    public function restore(Article $article): JsonResponse
    {
        $article->restore();

        return new JsonResponse(['data' => []]);
    }

    /**
     * Permanently delete an article. Guarded by the forceDelete ability.
     *
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Http\JsonResponse
     */
    public function forceDestroy(Article $article): JsonResponse
    {
        $article->forceDelete();

        return new JsonResponse(['data' => []]);
    }
}
