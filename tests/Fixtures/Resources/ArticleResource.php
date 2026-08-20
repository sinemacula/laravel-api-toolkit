<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use Tests\Fixtures\Models\Article;

/**
 * Fixture article resource.
 *
 * Exposes a scalar-only default field set over a wide, soft-deleting table so a
 * narrowed base-table SELECT can be proven to drop columns. A needs-annotated
 * accessor proves declared narrowing, while the relation feeds the safety set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'slug', 'status'];

    /**
     * Opt in to soft-delete visibility so the trashed criteria can widen the
     * scope for this fixture.
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
            Field::scalar('slug'),
            Field::scalar('status'),
            Field::scalar('views')->sortable(),
            Field::accessor('summary_excerpt', static function ($resource): string {

                $article = $resource->resource;

                assert($article instanceof Article);

                return mb_substr($article->summary, 0, 20);
            })->needs('summary'),
            Field::accessor('author_name', static function ($resource): ?string {

                $article = $resource->resource;

                assert($article instanceof Article);

                return $article->author?->name;
            })->needs('status')->extras('author'),
            Relation::to('author', UserResource::class),
        );
    }
}
