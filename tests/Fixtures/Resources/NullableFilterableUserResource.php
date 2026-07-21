<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource exposing a nullable filterable column.
 *
 * The `organization_id` column is nullable on the users table and is declared
 * filterable here so the `$null` / `$notNull` operators can be exercised under
 * the allowlist posture. The scalar fields alongside it give a stable set to
 * assert the narrowed rows against.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NullableFilterableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'nullable_filterable_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email', 'organization_id'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable()->sortable(),
            Field::scalar('name')->filterable(),
            Field::scalar('email')->filterable(),
            Field::scalar('organization_id')->filterable(),
            Field::scalar('status'),
        );
    }
}
