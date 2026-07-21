<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\User;

/**
 * Fixture user resource whose email field is guarded per row.
 *
 * The email guard reads the row being rendered and reveals the field only
 * when that user's status is active. Driven as a plain JSON collection, a
 * two-row set (one active, one inactive) therefore carries email on the
 * active row and omits it on the inactive row within the same body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PerItemGuardedUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'per_item_guarded_users';

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
            Field::scalar('id'),
            Field::scalar('name'),
            Field::scalar('email')->guard(static function ($resource): bool {

                $model = $resource->resource;

                if (!$model instanceof User) {
                    return false;
                }

                // status casts to the UserStatus enum, so read the cast value.
                return $model->getAttributeValue('status') === UserStatus::ACTIVE;
            }),
        );
    }
}
