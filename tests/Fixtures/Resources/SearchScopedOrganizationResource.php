<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture organization resource declaring its own search surface.
 *
 * It sits on the far side of a relation from a searchable root: `name` is
 * declared searchable under the anywhere-match and filterable besides, so a
 * search that followed the relation would have a column to match on, and a term
 * carried only by an organization row would reach the users belonging to it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SearchScopedOrganizationResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'search_scoped_organizations';

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
            Field::scalar('name')->filterable(Capability::EXACT)->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('slug'),
        );
    }
}
