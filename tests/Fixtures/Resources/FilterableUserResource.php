<?php

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource that declares an explicit query surface.
 *
 * The filterable, sortable, and traversable sets are deliberately distinct so
 * the allowlist enforcement can be exercised independently per capability:
 * `email` is filterable but not sortable, `created_at` is sortable but not
 * filterable, `status` is presented but neither, and `organization` is a real
 * relation that is not traversable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class FilterableUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'filterable_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable()->sortable(),
            Field::scalar('name')->filterable()->sortable(),
            Field::scalar('email')->filterable(),
            Field::scalar('status'),
            Field::timestamp('created_at')->sortable(),
            Relation::to('posts', PostResource::class)->traversable(),
            Relation::to('organization', OrganizationResource::class),
        );
    }
}
