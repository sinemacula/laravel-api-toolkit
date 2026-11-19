<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture article resource that opts in to trashed visibility and bounds its
 * soft-deleting sibling articles per parent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BoundedArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'bounded_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'slug', 'siblingArticles'];

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
            Field::scalar('slug'),
            Relation::to('siblingArticles', ArticleResource::class)->limit(2),
        );
    }
}
