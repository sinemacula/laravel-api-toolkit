<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource whose query surface is reached under an alias.
 *
 * Presents its email column as a differently named property, so a reader of the
 * surface has to be told both the property carried in the response and the
 * column a filter, an order, or a search names. The same column carries an
 * exempted sort, so the recorded reason travels alongside a declaration no
 * index holds, and one relation is traversable while another is not.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AliasedQueryableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'aliased_queryable_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'email'];

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
            Field::scalar('email_address', 'email')
                ->filterable(Capability::EXACT)
                ->sortable()
                ->unindexed('the table is bounded by the seat count')
                ->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('notes'),
            Relation::to('organization', OrganizationResource::class)->traversable(),
            Relation::to('posts', PostResource::class),
        );
    }
}
