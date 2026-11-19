<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Fixture user resource shaped like a production listing endpoint.
 *
 * It carries a to-one relation, a relation accessor, a traversable to-many
 * relation with a traversable child, an index-backed search and default
 * aggregates.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ListingUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'listing_users';

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
            Field::scalar('id')->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable()->searchable(SearchStrategy::EXACT),
            Field::scalar('email')->sortable(),
            Relation::to('organization', OrganizationResource::class)->traversable(),
            Relation::to('profile', 'bio', 'profile_bio'),
            Relation::to('posts', TraversingPostResource::class)->traversable(),
            Count::of('posts')->default(),
            Sum::of('posts', 'id')->default(),
        );
    }
}
