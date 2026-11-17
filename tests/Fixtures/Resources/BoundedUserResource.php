<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource whose to-many relations are bounded per parent.
 *
 * `posts` and `latest_posts` declare a limit over one relation, the second
 * ordered newest first; `comments` takes the configured default; `articles`
 * reaches a soft-deleting relation whose resource opts in to trashed rows;
 * `notes` is a polymorphic one-to-many.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BoundedUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'bounded_users';

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
            Field::scalar('name'),
            Relation::to('posts', BoundedPostResource::class)->limit(5),
            Relation::to('posts', BoundedPostResource::class, 'latest_posts')->limit(5)->constrain(
                static function (Builder $query): void {
                    $query->latest();
                },
            ),
            Relation::to('comments', CommentResource::class),
            Relation::to('articles', ArticleResource::class)->limit(2),
            Relation::to('notes', NoteResource::class)->limit(3),
        );
    }
}
