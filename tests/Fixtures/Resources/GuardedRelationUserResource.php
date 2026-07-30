<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource exercising a request-scoped guard on a relation field.
 *
 * The organization relation carries a request-scoped guard so the embedded
 * relation can be proven to drop out of the response entirely unless the
 * request permits it, guarding against a guarded relation leaking.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GuardedRelationUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'guarded_relation_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'organization'];

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
            Relation::to('organization', OrganizationResource::class)
                ->guard(static fn ($resource, $request): bool => $request?->query('include_org') === 'yes'),
        );
    }
}
