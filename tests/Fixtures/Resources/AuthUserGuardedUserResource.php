<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource whose email field is guarded on the authenticated user.
 *
 * The email guard reads the request's authenticated user and reveals the
 * field only when that user is an admin. The is_admin accessor on the User
 * and ActorUser fixtures resolves to true when the authenticated user's email
 * begins with "admin@", so the field is absent for a guest and present only
 * under actingAs() of an admin user.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AuthUserGuardedUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'auth_user_guarded_users';

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
            Field::scalar('email')->guard(static fn ($resource, $request): bool => data_get($request?->user(), 'is_admin') === true),
        );
    }
}
