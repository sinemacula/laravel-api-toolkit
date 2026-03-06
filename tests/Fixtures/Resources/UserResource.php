<?php

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Count;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;
use SineMacula\ApiToolkit\Http\Resources\Schema\Relation;

/**
 * Fixture user resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class UserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'users';

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
            Field::scalar('id'),
            Field::scalar('name'),
            Field::scalar('email'),
            Field::scalar('status'),
            Field::timestamp('created_at'),
            Field::timestamp('updated_at'),
            Field::compute('full_label', fn ($resource) => $resource->name . ' <' . $resource->email . '>'),
            Relation::to('organization', OrganizationResource::class),
            Relation::to('profile', 'bio', 'profile_bio'),
            Relation::to('posts', PostResource::class),
            Count::of('posts')->default(),
        );
    }
}
