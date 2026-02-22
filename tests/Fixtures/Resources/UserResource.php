<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Count;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;
use SineMacula\ApiToolkit\Http\Resources\Schema\Relation;

class UserResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'user';
    protected static array $default   = ['id', 'name', 'computed_method', 'organization', 'counts'];
    protected array $fixed            = ['local_fixed'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('name'),
            Field::accessor('name', 'name', 'name_upper')->transform(static fn (ApiResource $resource, mixed $value) => strtoupper((string) $value)),
            Field::accessor('nickname', 'nickname'),
            Field::compute('computed_method', 'resolveComputedMethod'),
            Field::compute('computed_callback', static fn (ApiResource $resource) => 'callback:' . $resource->resource->id),
            Field::scalar('secret')->guard(static fn () => false),
            Relation::to('organization', OrganizationResource::class)
                ->fields(['id', 'name'])
                ->extras('organization'),
            Relation::to('organization', 'name', 'organization_name'),
            Relation::to('posts', PostResource::class)
                ->fields(['id', 'title'])
                ->constrain(static fn ($query) => $query->where('published', true)),
            Count::of('posts')->as('posts')->default(),
            Count::of('posts')->as('published_posts')->constrain(static fn ($query) => $query->where('published', true)),
            ['invalid_compute'  => ['compute' => 'missingComputeMethod']],
            ['invalid_accessor' => ['accessor' => 123]],
            ['raw_relation'     => ['relation' => ['organization']]],
        );
    }

    protected function resolveComputedMethod(?Request $request): string
    {
        return 'computed:' . ($request?->query('suffix', 'none') ?? 'none');
    }
}
