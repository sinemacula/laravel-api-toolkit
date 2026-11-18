<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource whose email and posts only an admin may see or query.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GuardedQueryableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'guarded_queryable_users';

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
        $admin = static fn (?Request $request): bool => data_get($request?->user(), 'is_admin') === true;

        return Field::set(
            Field::scalar('id')->filterable(Capability::RANGE)->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable(),
            Field::scalar('email')->visibleTo($admin)->filterable(Capability::EXACT)->sortable(),
            Relation::to('posts', GuardedQueryablePostResource::class)->visibleTo($admin)->traversable(),
        );
    }
}
