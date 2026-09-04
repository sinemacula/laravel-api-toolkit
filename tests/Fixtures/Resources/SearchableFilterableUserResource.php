<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource declaring both a search surface and a query surface.
 *
 * The two surfaces overlap without matching: `name` and `email` are searchable
 * under the anywhere-match, `status` is filterable but never searchable, so a
 * search and a filter can be proven to compose rather than to substitute for
 * one another. Both searchable columns carry the one strategy, which is what
 * every supported engine resolves from an index in a single predicate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SearchableFilterableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'searchable_filterable_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email', 'status'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable()->sortable(),
            Field::scalar('name')->filterable()->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('email')->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('status')->filterable(),
        );
    }
}
