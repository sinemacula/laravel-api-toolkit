<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource carrying every read modifier on one surface.
 *
 * `name` and `email` are searchable, `name` and `id` are sortable, and `status`
 * is filterable but never searchable, so a single request can compose a search,
 * a filter, a two-column sort and a fieldset that renders neither the searched
 * `email` nor the filtered `status`. Every field is a plain scalar mapping to
 * its own column, so the base-table projection narrows rather than falling back
 * to the full column set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CombinedSearchUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'combined_search_users';

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
            Field::scalar('id')->filterable(Capability::RANGE)->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable()->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('email')->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('status')->filterable(Capability::ENUM),
        );
    }
}
