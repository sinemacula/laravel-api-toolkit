<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture article resource served by the CRUD controller, with its author, its
 * tags and a query surface a client can filter, order and search.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TaggedArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'tagged_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'slug', 'status'];

    /**
     * Expose soft-deleted articles to the trashed parameter.
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
            Field::scalar('slug')->searchable(SearchStrategy::EXACT),
            Field::scalar('body'),
            Field::scalar('summary'),
            Field::scalar('status')->filterable(Capability::ENUM),
            Field::scalar('views')->filterable(Capability::RANGE)->sortable(),
            Field::timestamp('created_at'),
            Field::timestamp('updated_at'),
            Relation::to('author', UserResource::class)->traversable(),
            Relation::to('tags', TagResource::class)->traversable(),
            Count::of('tags'),
        );
    }
}
