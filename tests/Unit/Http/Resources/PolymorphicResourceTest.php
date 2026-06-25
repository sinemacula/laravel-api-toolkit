<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\PolymorphicResource;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the polymorphic resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PolymorphicResource::class)]
final class PolymorphicResourceTest extends TestCase
{
    /**
     * Test that toArray returns null when resource is null.
     *
     * @return void
     */
    public function testToArrayReturnsNullWhenResourceIsNull(): void
    {
        $resource = new PolymorphicResource(null);

        $request = request();
        $result  = $resource->toArray($request);

        self::assertNull($result);
    }

    /**
     * Test that toArray maps resource via config resource_map.
     *
     * @return void
     */
    public function testToArrayMapsResourceViaConfigResourceMap(): void
    {
        $user = User::create([
            'name'  => 'Polymorphic',
            'email' => 'poly@example.com',
        ]);

        $resource = new PolymorphicResource($user);
        $result   = $resource->toArray(request());

        self::assertIsArray($result);
        self::assertArrayHasKey('_type', $result);
        self::assertSame('users', $result['_type']);
    }

    /**
     * Test that toArray includes _type from mapped resource.
     *
     * @return void
     */
    public function testToArrayIncludesTypeFromMappedResource(): void
    {
        $org = Organization::create([
            'name' => 'PolyOrg',
            'slug' => 'polyorg',
        ]);

        $resource = new PolymorphicResource($org);
        $result   = $resource->toArray(request());

        self::assertIsArray($result);
        self::assertSame('organizations', $result['_type']);
    }

    /**
     * Test that toArray throws LogicException for unmapped resource.
     *
     * @return void
     */
    public function testToArrayThrowsLogicExceptionForUnmappedResource(): void
    {
        assert($this->app !== null);

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->app['config'];
        $config->set('api-toolkit.resources.resource_map', []);

        $user = User::create([
            'name'  => 'Unmapped',
            'email' => 'unmapped@example.com',
        ]);

        $resource = new PolymorphicResource($user);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Resource not found for:');

        $resource->toArray(request());
    }

    /**
     * Test that withAll propagates to mapped resource.
     *
     * @return void
     */
    public function testWithAllPropagatesToMappedResource(): void
    {
        $user = User::create([
            'name'   => 'AllPoly',
            'email'  => 'allpoly@example.com',
            'status' => 'active',
        ]);

        $resource = new PolymorphicResource($user);
        $resource->withAll();

        $result = $resource->toArray(request());

        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('full_label', $result);
    }

    /**
     * Test that toArray resolves correct fields for different model types.
     *
     * @return void
     */
    public function testToArrayResolvesCorrectFieldsForDifferentModelTypes(): void
    {
        $user = User::create([
            'name'  => 'TypeA',
            'email' => 'typea@example.com',
        ]);

        $post = Post::create([
            'user_id'   => $user->getKey(),
            'title'     => 'Post Title',
            'body'      => 'Post body',
            'published' => true,
        ]);

        $userResource = new PolymorphicResource($user);
        $postResource = new PolymorphicResource($post);

        $userResult = $userResource->toArray(request());
        $postResult = $postResource->toArray(request());

        self::assertIsArray($userResult);
        self::assertIsArray($postResult);
        self::assertSame('users', $userResult['_type']);
        self::assertSame('posts', $postResult['_type']);
    }

    /**
     * Test that toArray returns null for empty/falsy resource.
     *
     * @return void
     */
    public function testToArrayReturnsNullForFalsyResource(): void
    {
        $resource = new PolymorphicResource(null);

        $result = $resource->toArray(request());

        self::assertNull($result);
    }

    /**
     * Test that withFields propagates to mapped resource via constructor.
     *
     * @return void
     */
    public function testWithFieldsPropagatesToMappedResource(): void
    {
        $user = User::create([
            'name'   => 'FieldsPoly',
            'email'  => 'fieldspoly@example.com',
            'status' => 'active',
        ]);

        $resource = new PolymorphicResource($user);
        $resource->withFields(['name']);

        $result = $resource->toArray(request());

        self::assertArrayHasKey('_type', $result);
        self::assertArrayHasKey('name', $result);
    }

    /**
     * Test that PolymorphicResource extends JsonResource directly.
     *
     * @return void
     */
    public function testPolymorphicResourceExtendsJsonResource(): void
    {
        $resource = new PolymorphicResource(null);

        self::assertInstanceOf(JsonResource::class, $resource);
        self::assertSame(JsonResource::class, get_parent_class($resource));
    }

    /**
     * Test that withAll sets the all flag locally and returns the instance
     * for fluent chaining.
     *
     * @return void
     */
    public function testPolymorphicResourceWithAllSetsFlag(): void
    {
        $resource = new PolymorphicResource(null);

        $result = $resource->withAll();

        self::assertSame($resource, $result);
    }

    /**
     * Test that withFields sets the fields locally and returns the instance
     * for fluent chaining.
     *
     * @return void
     */
    public function testPolymorphicResourceWithFieldsSetsFields(): void
    {
        $resource = new PolymorphicResource(null);

        $result = $resource->withFields(['name', 'email']);

        self::assertSame($resource, $result);
    }

    /**
     * Test that withoutFields sets the excluded fields locally and returns
     * the instance for fluent chaining.
     *
     * @return void
     */
    public function testPolymorphicResourceWithoutFieldsSetsExcludedFields(): void
    {
        $resource = new PolymorphicResource(null);

        $result = $resource->withoutFields(['email']);

        self::assertSame($resource, $result);
    }

    /**
     * Test that mapping a resource does not eager load missing relations on
     * the underlying model.
     *
     * @return void
     */
    public function testToArrayDoesNotEagerLoadMissingRelations(): void
    {
        $organization = Organization::create([
            'name' => 'PolyLazy Corp',
            'slug' => 'polylazy-corp',
        ]);

        $user = User::create([
            'name'            => 'PolyLazy',
            'email'           => 'polylazy@example.com',
            'organization_id' => $organization->id,
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => ['users' => 'id,name,organization'],
        ]);

        $parser->parse($request);

        $resource = new PolymorphicResource($user);
        $result   = $resource->toArray(request());

        self::assertIsArray($result);
        self::assertFalse($user->relationLoaded('organization'));
        self::assertArrayNotHasKey('organization', $result);
    }

    /**
     * Define the environment setup.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];
        $config->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
        ]);
    }
}
