<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource pairing a search surface with traversable relations.
 *
 * `name` and `email` are the only searchable columns, and both belong to the
 * root resource. The belongs-to and has-many relations are declared traversable
 * and each points at a resource that declares its own searchable columns, so a
 * filter can reach a related row while a search may not: the two surfaces
 * disagree about how far they travel, which is the boundary this resource
 * exists to make observable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SearchScopedUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'search_scoped_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable(Capability::RANGE),
            Field::scalar('name')->filterable(Capability::EXACT)->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('email')->searchable(SearchStrategy::SUBSTRING),
            Relation::to('organization', SearchScopedOrganizationResource::class)->traversable(),
            Relation::to('posts', SearchScopedPostResource::class)->traversable(),
        );
    }
}
