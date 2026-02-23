<?php

namespace Tests\Unit\Http\Resources;

use LogicException;
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
class PolymorphicResourceTest extends TestCase
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

        static::assertNull($result);
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

        static::assertIsArray($result);
        static::assertArrayHasKey('_type', $result);
        static::assertSame('users', $result['_type']);
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

        static::assertSame('organizations', $result['_type']);
    }

    /**
     * Test that toArray throws LogicException for unmapped resource.
     *
     * @return void
     */
    public function testToArrayThrowsLogicExceptionForUnmappedResource(): void
    {
        $this->app['config']->set('api-toolkit.resources.resource_map', []);

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

        static::assertArrayHasKey('name', $result);
        static::assertArrayHasKey('email', $result);
        static::assertArrayHasKey('status', $result);
        static::assertArrayHasKey('full_label', $result);
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
            'user_id'   => $user->id,
            'title'     => 'Post Title',
            'body'      => 'Post body',
            'published' => true,
        ]);

        $user_resource = new PolymorphicResource($user);
        $post_resource = new PolymorphicResource($post);

        $user_result = $user_resource->toArray(request());
        $post_result = $post_resource->toArray(request());

        static::assertSame('users', $user_result['_type']);
        static::assertSame('posts', $post_result['_type']);
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

        static::assertNull($result);
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

        static::assertArrayHasKey('_type', $result);
        static::assertArrayHasKey('name', $result);
    }

    /**
     * Define the environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment(\Illuminate\Foundation\Application $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
        ]);
    }
}
