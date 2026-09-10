<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture tag resource declaring a full query surface.
 *
 * Stands in for the plain tag resource where a test needs a resource whose
 * emitted schema carries a filterable, sortable, and searchable surface, so the
 * surface can be traced into one audience's document and looked for in
 * another's. Both sortable columns are led by an index the fixture table
 * carries - the primary key and the unique name - so the declarations are ones
 * schema validation would accept.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryableTagResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'queryable_tags';

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
            Field::scalar('id')->filterable(Capability::RANGE)->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable()->searchable(SearchStrategy::PREFIX),
            Field::timestamp('created_at'),
            Field::timestamp('updated_at'),
        );
    }
}
