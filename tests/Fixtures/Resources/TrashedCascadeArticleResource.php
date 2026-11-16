<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture article resource that opts in to trashed visibility and embeds two
 * soft-deleting relations.
 *
 * The sibling articles resolve to a resource that opts in and the author's
 * comments to one that does not, so one honoured request proves the cascade is
 * gated per relation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TrashedCascadeArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'trashed_cascade_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'siblingArticles', 'authorComments'];

    /**
     * Opt in to soft-delete visibility so the root honours a trashed request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     *
     * @SuppressWarnings("php:S1172")
     */
    #[\Override]
    public static function allowsTrashed(Request $request): bool
    {
        return true;
    }

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('title'),
            Relation::to('siblingArticles', ArticleResource::class),
            Relation::to('authorComments', CommentResource::class),
        );
    }
}
