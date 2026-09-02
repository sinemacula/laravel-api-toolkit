<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource exposing a nullable filterable column.
 *
 * The `organization_id` column is nullable on the users table and is declared
 * filterable here so the `$null` / `$notNull` operators can be exercised under
 * the allowlist posture. The `status` column is declared with the enum
 * capability, the only one whose value domain is closed enough to answer
 * `$neq`, so the negation operator has a column to be driven against. The
 * scalar fields alongside them give a stable set to assert the narrowed rows
 * against.
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
            Field::scalar('id')->filterable(Capability::RANGE)->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT),
            Field::scalar('email')->filterable(Capability::EXACT),
            Field::scalar('organization_id')->filterable(Capability::EXACT),
            Field::scalar('status')->filterable(Capability::ENUM),
        );
    }
}
