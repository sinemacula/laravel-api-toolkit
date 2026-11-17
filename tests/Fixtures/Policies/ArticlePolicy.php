<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Tests\Fixtures\Models\Article;

/**
 * Fixture article policy granting the trashed-row abilities to the author.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ArticlePolicy
{
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
