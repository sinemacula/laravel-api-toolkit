<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture author resource.
 *
 * Maps the User model and eager-loads two soft-deleting child relations through
 * distinct resources - one that opts in to trashed visibility and one that does
 * not - so a single request can prove the cascade is gated independently per
 * relation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AuthorResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'authors';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'articles', 'comments'];

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
            Relation::to('articles', ArticleResource::class),
            Relation::to('comments', CommentResource::class),
        );
    }
}
