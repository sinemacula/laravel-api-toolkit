<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Fixture user resource ordered by its aggregates and its latest article.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SortableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'sortable_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name'];

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
            Field::scalar('name')->sortable(),
            Count::of('posts')->sortable('Leaderboard over a few hundred authors'),
            Count::of('posts', 'published_posts')->constrain(static function (Builder $query): void {
                $query->where('published', true);
            })->sortable('Leaderboard over a few hundred authors'),
            Sum::of('articles', 'views', 'article_views')->sortable('Admin list of a few hundred authors'),
            Relation::to('latestArticle', ArticleResource::class)->sortable('Admin list of a few hundred authors'),
            Relation::to('latestPost', SortableAuthoredPostResource::class)->sortable('Admin list of a few hundred authors'),
        );
    }
}
