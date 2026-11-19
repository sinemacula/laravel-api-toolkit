<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\User;

/**
 * Fixture user resource rendering its enum-cast status through an accessor
 * under `state`, so only a filter on the `status` column references the enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AliasedStatusUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'state'];

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
            Field::accessor('status', static function ($resource): string {

                $user = $resource->resource;

                assert($user instanceof User);

                $status = $user->getAttributeValue('status');

                assert($status instanceof UserStatus);

                return $status->value;
            }, 'state')->needs('status')->filterable(Capability::ENUM),
        );
    }
}
