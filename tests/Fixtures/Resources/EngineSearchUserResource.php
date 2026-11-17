<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource searched through each engine's own driver.
 *
 * `name` and `email` are matched together, as the MySQL full-text index over
 * both columns requires, and `name` is also filterable and sortable so one
 * request can search, filter, order and page.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EngineSearchUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'engine_search_users';

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
            Field::scalar('id')->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable()->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('email')->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('status')->filterable(Capability::ENUM),
            Relation::to('posts', PostResource::class),
        );
    }
}
