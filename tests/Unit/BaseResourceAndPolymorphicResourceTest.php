<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\PolymorphicResource;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class BaseResourceAndPolymorphicResourceTest extends TestCase
{
    public function testBaseResourceFieldHelpersAreChainable(): void
    {
        $resource = new UserResource((object) ['id' => 1, 'name' => 'Alice']);

        static::assertSame($resource, $resource->withFields(['id', 'name']));
        static::assertSame($resource, $resource->withoutFields(['name']));
        static::assertSame($resource, $resource->withAll());
    }

    public function testPolymorphicResourceReturnsNullWhenUnderlyingResourceIsNull(): void
    {
        $resource = new PolymorphicResource(null);

        static::assertNull($resource->toArray(Request::create('/')));
    }

    public function testPolymorphicResourceResolvesMappedResourceAndIncludesType(): void
    {
        $organization = Organization::query()->create(['name' => 'Org']);
        $user         = User::query()->create([
            'name'            => 'Alice',
            'organization_id' => $organization->id,
        ]);

        $user->setRelation('organization', $organization);

        $resource = (new PolymorphicResource($user))->withAll();

        $array = $resource->toArray(Request::create('/api/users'));

        static::assertSame('user', $array['_type']);
        static::assertArrayHasKey('name', $array);
    }

    public function testPolymorphicResourceThrowsWhenMapIsMissing(): void
    {
        config()->set('api-toolkit.resources.resource_map', []);

        $resource = new PolymorphicResource((object) ['id' => 1]);

        $this->expectException(\LogicException::class);

        $resource->toArray(Request::create('/'));
    }
}
