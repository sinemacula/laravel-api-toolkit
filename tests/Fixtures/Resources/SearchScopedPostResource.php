<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture post resource declaring its own search surface.
 *
 * The has-many counterpart to the organization fixture: `title` and `body` are
 * declared searchable under the anywhere-match, so a term carried only by a
 * post would reach the post's author if a search followed the relation. `title`
 * is filterable as well, which lets a test prove the related row is reachable
 * by a filter while the same term is unreachable by a search.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SearchScopedPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'search_scoped_posts';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title'];

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
            Field::scalar('title')->filterable(Capability::EXACT)->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('body')->searchable(SearchStrategy::SUBSTRING),
        );
    }
}
