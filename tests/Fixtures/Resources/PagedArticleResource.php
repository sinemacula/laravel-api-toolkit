<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture article resource that takes every read modifier at once: a search, a
 * filter, a sort, a fieldset and trashed visibility.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PagedArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'paged_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'status', 'views'];

    /**
     * Opt in to soft-delete visibility.
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
            Field::scalar('title')->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('status')->filterable(Capability::ENUM),
            Field::scalar('views')->filterable(Capability::RANGE)->sortable(),
        );
    }
}
