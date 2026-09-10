<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture article resource declaring the same search surface with the trashed
 * gate held closed.
 *
 * Identical to its opted-in sibling but for the gate, so a `?trashed=with`
 * carried alongside a term can be proven to widen nothing: a search is never
 * the thing that opens soft-delete visibility.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TrashedSearchClosedArticleResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'trashed_search_closed_articles';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'status'];

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
            Field::scalar('status'),
        );
    }
}
