<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\Http\Enums\HttpStatus;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Tests\Fixtures\Input\ArticleStorePayload;
use Tests\Fixtures\Input\ArticleUpdatePayload;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Resources\TaggedArticleResource;

/**
 * Fixture authorized resource controller over a soft-deleting model, written as
 * a consuming application would write it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Article::class)]
final class ArticleController extends AuthorizedController
{
    /**
     * Constructor.
     *
     * @param  \Tests\Fixtures\Repositories\ArticleRepository  $articles
     */
    public function __construct(

        /** The articles the actions read and write */
        private readonly ArticleRepository $articles,
    ) {}

    /**
     * List the articles the query selects, a page at a time.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function index(): HttpResponse
    {
        return $this->respondWithCollection(TaggedArticleResource::collection($this->articles->withApiCriteria()->paginate()));
    }

    /**
     * Create an article by the current user, with its tags, and render it with
     * the column defaults the insert applied.
     *
     * @param  \Tests\Fixtures\Input\ArticleStorePayload  $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function store(ArticleStorePayload $payload): HttpResponse
    {
        $article = new Article;

        $this->articles->persist($article, [...$payload->toArray(), 'author' => Auth::id()]);

        $article->refresh();

        return $this->respondWithItem(new TaggedArticleResource($article, loadMissing: true), HttpStatus::CREATED);
    }

    /**
     * Show the given article.
     *
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function show(Article $article): HttpResponse
    {
        return $this->respondWithItem(new TaggedArticleResource($article, loadMissing: true));
    }

    /**
     * Update the fields of the given article that the request sent.
     *
     * @param  \Tests\Fixtures\Input\ArticleUpdatePayload  $payload
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function update(ArticleUpdatePayload $payload, Article $article): HttpResponse
    {
        $this->articles->persist($article, $payload->toArray());

        return $this->respondWithItem(new TaggedArticleResource($article, loadMissing: true));
    }

    /**
     * Soft delete the given article.
     *
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Http\Response
     */
    public function destroy(Article $article): Response
    {
        $article->delete();

        return new Response(status: HttpStatus::NO_CONTENT->getCode());
    }

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
