<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use Tests\Fixtures\Models\User;

/**
 * Fixture user resource fixing one field of each kind and no defaults.
 *
 * Only the plain column read can be relied on in every response; the others may
 * be left out, and one fixed key is not declared at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FixedFieldsUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'fixed_users';

    /** @var array<int, string> */
    protected static array $default = [];

    /** @var array<int, string> */
    protected static array $fixed = ['undeclared', 'email', 'status', 'full_label', 'display_label', 'organization', 'name'];

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
            Field::scalar('email')->guard(static fn (): bool => true),
            Field::scalar('status')->transform(static fn ($resource, $value): mixed => $value),
            Field::compute('full_label', static fn (): string => 'label'),
            Field::accessor('display_label', static function ($resource): string {

                $user = $resource->resource;

                assert($user instanceof User);

                return $user->name;
            }),
            Relation::to('organization', OrganizationResource::class),
        );
    }
}
