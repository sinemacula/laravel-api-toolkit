<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Models\Article;

/**
 * Fixture article policy: a suspended actor may neither list nor create, a
 * draft is visible only to its author, and only the author may change it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ArticlePolicy
{
    /**
     * Determine whether the actor may list articles.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @return \Illuminate\Auth\Access\Response
     */
    public function viewAny(Authenticatable $actor): Response
    {
        return $this->active($actor);
    }

    /**
     * Determine whether the actor may view the given article.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Auth\Access\Response
     */
    public function view(Authenticatable $actor, Article $article): Response
    {
        return $article->status === 'draft' ? $this->authored($actor, $article) : Response::allow();
    }

    /**
     * Determine whether the actor may create an article.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @return \Illuminate\Auth\Access\Response
     */
    public function create(Authenticatable $actor): Response
    {
        return $this->active($actor);
    }

    /**
     * Determine whether the actor may update the given article.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Auth\Access\Response
     */
    public function update(Authenticatable $actor, Article $article): Response
    {
        return $this->authored($actor, $article);
    }

    /**
     * Determine whether the actor may delete the given article.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Auth\Access\Response
     */
    public function delete(Authenticatable $actor, Article $article): Response
    {
        return $this->authored($actor, $article);
    }

    /**
     * Determine whether the actor may restore the given article.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Auth\Access\Response
     */
    public function restore(Authenticatable $actor, Article $article): Response
    {
        return $this->authored($actor, $article);
    }

    /**
     * Determine whether the actor may permanently delete the given article.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Auth\Access\Response
     */
    public function forceDelete(Authenticatable $actor, Article $article): Response
    {
        return $this->authored($actor, $article);
    }

    /**
     * Allow an actor that is not suspended.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @return \Illuminate\Auth\Access\Response
     */
    private function active(Authenticatable $actor): Response
    {
        return $actor instanceof ActorUser && $actor->status === 'suspended' ? Response::deny() : Response::allow();
    }

    /**
     * Allow the article's author and deny everyone else.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\Article  $article
     * @return \Illuminate\Auth\Access\Response
     */
    private function authored(Authenticatable $actor, Article $article): Response
    {
        return $article->user_id === $actor->getAuthIdentifier() ? Response::allow() : Response::deny();
    }
}
