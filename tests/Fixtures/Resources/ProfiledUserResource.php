<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use Tests\Fixtures\Models\Organization;

/**
 * Fixture user resource embedding its organization and profile, and naming its
 * organization through a declared transform.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ProfiledUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'profiled_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'organization', 'profile', 'organization_name'];

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
            Field::scalar('name'),
            Relation::to('organization', OrganizationResource::class),
            Relation::to('profile', ProfileResource::class),
            Relation::to('organization', OrganizationResource::class, 'organization_name')
                ->transform(static fn ($resource, mixed $organization): ?string => $organization instanceof JsonResource
                    && $organization->resource instanceof Organization ? $organization->resource->name : null)
                ->openapi()
                ->type('string')
                ->nullable()
                ->end(),
        );
    }
}
