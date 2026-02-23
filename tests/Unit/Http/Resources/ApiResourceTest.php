<?php

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Schema\Count;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;
use SineMacula\ApiToolkit\Http\Resources\Schema\Relation;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the base API resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
class ApiResourceTest extends TestCase
{
    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clearSchemaCache();

        parent::tearDown();
    }

    /**
     * Test that getResourceType returns the lowercased RESOURCE_TYPE constant.
     *
     * @return void
     */
    public function testGetResourceTypeReturnsLowercasedConstant(): void
    {
        static::assertSame('users', UserResource::getResourceType());
        static::assertSame('organizations', OrganizationResource::getResourceType());
        static::assertSame('posts', PostResource::getResourceType());
        static::assertSame('tags', TagResource::getResourceType());
    }

    /**
     * Test that getResourceType throws LogicException when RESOURCE_TYPE is
     * not defined.
     *
     * @return void
     */
    public function testGetResourceTypeThrowsLogicExceptionWhenNotDefined(): void
    {
        $resource = new class (null) extends ApiResource {
            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return [];
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The RESOURCE_TYPE constant must be defined on the resource');

        $resource::getResourceType();
    }

    /**
     * Test that getDefaultFields returns the static default array.
     *
     * @return void
     */
    public function testGetDefaultFieldsReturnsStaticDefaultArray(): void
    {
        static::assertSame(['id', 'name', 'email'], UserResource::getDefaultFields());
        static::assertSame(['id', 'name', 'slug'], OrganizationResource::getDefaultFields());
        static::assertSame(['id', 'title'], PostResource::getDefaultFields());
        static::assertSame(['id', 'name'], TagResource::getDefaultFields());
    }

    /**
     * Test that getAllFields returns all field keys excluding count metrics.
     *
     * @return void
     */
    public function testGetAllFieldsReturnsAllFieldKeysExcludingCounts(): void
    {
        $all_fields = UserResource::getAllFields();

        static::assertContains('id', $all_fields);
        static::assertContains('name', $all_fields);
        static::assertContains('email', $all_fields);
        static::assertContains('status', $all_fields);
        static::assertContains('created_at', $all_fields);
        static::assertContains('updated_at', $all_fields);
        static::assertContains('full_label', $all_fields);
        static::assertContains('organization', $all_fields);
        static::assertContains('profile_bio', $all_fields);
        static::assertContains('posts', $all_fields);

        foreach ($all_fields as $field) {
            static::assertStringNotContainsString('__count__', $field);
        }
    }

    /**
     * Test that resolveFields returns API query fields when set.
     *
     * @return void
     */
    public function testResolveFieldsReturnsApiQueryFieldsWhenSet(): void
    {
        $parser = $this->app->make('api.query');

        $request = Request::create('/', 'GET', [
            'fields' => ['users' => 'id,name,status'],
        ]);

        $parser->parse($request);

        $fields = UserResource::resolveFields();

        static::assertSame(['id', 'name', 'status'], $fields);
    }

    /**
     * Test that resolveFields returns defaults when no API query fields set.
     *
     * @return void
     */
    public function testResolveFieldsReturnsDefaultsWhenNoQueryFields(): void
    {
        $parser = $this->app->make('api.query');

        $request = Request::create('/', 'GET');

        $parser->parse($request);

        $fields = UserResource::resolveFields();

        static::assertSame(['id', 'name', 'email'], $fields);
    }

    /**
     * Test that resolve includes _type in output.
     *
     * @return void
     */
    public function testResolveIncludesTypeInOutput(): void
    {
        $user = User::create([
            'name'  => 'Alice',
            'email' => 'alice@example.com',
        ]);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        static::assertArrayHasKey('_type', $result);
        static::assertSame('users', $result['_type']);
    }

    /**
     * Test that resolve includes fixed fields (id, _type from config).
     *
     * @return void
     */
    public function testResolveIncludesFixedFields(): void
    {
        $user = User::create([
            'name'  => 'Bob',
            'email' => 'bob@example.com',
        ]);

        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => ['users' => 'name'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        static::assertArrayHasKey('_type', $result);
        static::assertArrayHasKey('id', $result);
        static::assertArrayHasKey('name', $result);
    }

    /**
     * Test that resolve filters based on requested fields.
     *
     * @return void
     */
    public function testResolveFiltersBasedOnRequestedFields(): void
    {
        $user = User::create([
            'name'   => 'Charlie',
            'email'  => 'charlie@example.com',
            'status' => 'active',
        ]);

        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => ['users' => 'id,name'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        static::assertArrayHasKey('name', $result);
        static::assertArrayNotHasKey('status', $result);
        static::assertArrayNotHasKey('email', $result);
    }

    /**
     * Test that resolve excludes fields via withoutFields.
     *
     * @return void
     */
    public function testResolveExcludesFieldsViaWithoutFields(): void
    {
        $user = User::create([
            'name'  => 'Dana',
            'email' => 'dana@example.com',
        ]);

        $resource = new UserResource($user);
        $resource->withoutFields(['email']);

        $result = $resource->resolve();

        static::assertArrayNotHasKey('email', $result);
        static::assertArrayHasKey('name', $result);
    }

    /**
     * Test that resolve with withAll includes all fields.
     *
     * @return void
     */
    public function testResolveWithWithAllIncludesAllFields(): void
    {
        $user = User::create([
            'name'   => 'Eve',
            'email'  => 'eve@example.com',
            'status' => 'active',
        ]);

        $resource = new UserResource($user);
        $resource->withAll();

        $result = $resource->resolve();

        static::assertArrayHasKey('name', $result);
        static::assertArrayHasKey('email', $result);
        static::assertArrayHasKey('status', $result);
        static::assertArrayHasKey('full_label', $result);
    }

    /**
     * Test that resolve with :all in API query includes all fields.
     *
     * @return void
     */
    public function testResolveWithAllInApiQueryIncludesAllFields(): void
    {
        $user = User::create([
            'name'   => 'Frank',
            'email'  => 'frank@example.com',
            'status' => 'active',
        ]);

        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => ['users' => ':all'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        static::assertArrayHasKey('name', $result);
        static::assertArrayHasKey('email', $result);
        static::assertArrayHasKey('status', $result);
        static::assertArrayHasKey('full_label', $result);
    }

    /**
     * Test that guards prevent field inclusion when they return false.
     *
     * @return void
     */
    public function testGuardsPreventFieldInclusionWhenReturnFalse(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'guarded_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name', 'secret'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::scalar('name'),
                    Field::scalar('secret')->guard(fn () => false),
                );
            }
        };

        $user = User::create([
            'name'  => 'Guarded',
            'email' => 'guarded@example.com',
        ]);

        $resource = new $resource_class($user);
        $resource->withFields(['id', 'name', 'secret']);

        $result = $resource->resolve();

        static::assertArrayHasKey('name', $result);
        static::assertArrayNotHasKey('secret', $result);
    }

    /**
     * Test that guards allow field inclusion when they return true.
     *
     * @return void
     */
    public function testGuardsAllowFieldInclusionWhenReturnTrue(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'guard_pass_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name', 'visible'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::scalar('name'),
                    Field::scalar('visible')->guard(fn () => true),
                );
            }
        };

        $user = User::create([
            'name'  => 'Visible',
            'email' => 'visible@example.com',
        ]);

        $user->visible = 'shown';

        $resource = new $resource_class($user);
        $resource->withFields(['name', 'visible']);

        $result = $resource->resolve();

        static::assertArrayHasKey('name', $result);
    }

    /**
     * Test that transformers modify resolved values.
     *
     * @return void
     */
    public function testTransformersModifyResolvedValues(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'transform_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::scalar('name')->transform(fn ($resource, $value) => strtoupper($value)),
                );
            }
        };

        $user = User::create([
            'name'  => 'lowercase',
            'email' => 'lower@example.com',
        ]);

        $resource = new $resource_class($user);
        $resource->withFields(['name']);

        $result = $resource->resolve();

        static::assertSame('LOWERCASE', $result['name']);
    }

    /**
     * Test that computed fields resolve via callables.
     *
     * @return void
     */
    public function testComputedFieldsResolveViaCallables(): void
    {
        $user = User::create([
            'name'  => 'Computed',
            'email' => 'computed@example.com',
        ]);

        $resource = new UserResource($user);
        $resource->withFields(['full_label']);

        $result = $resource->resolve();

        static::assertSame('Computed <computed@example.com>', $result['full_label']);
    }

    /**
     * Test that accessor fields resolve via string path.
     *
     * @return void
     */
    public function testAccessorFieldsResolveViaStringPath(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'accessor_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'nested_value'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::accessor('nested_value', 'name'),
                );
            }
        };

        $user = User::create([
            'name'  => 'Accessed',
            'email' => 'accessed@example.com',
        ]);

        $resource = new $resource_class($user);
        $resource->withFields(['nested_value']);

        $result = $resource->resolve();

        static::assertSame('Accessed', $result['nested_value']);
    }

    /**
     * Test that accessor fields resolve via callable.
     *
     * @return void
     */
    public function testAccessorFieldsResolveViaCallable(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'accessor_callable_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'computed_accessor'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::accessor('computed_accessor', fn ($resource) => 'custom:' . $resource->name),
                );
            }
        };

        $user = User::create([
            'name'  => 'CallableAccess',
            'email' => 'callable@example.com',
        ]);

        $resource = new $resource_class($user);
        $resource->withFields(['computed_accessor']);

        $result = $resource->resolve();

        static::assertSame('custom:CallableAccess', $result['computed_accessor']);
    }

    /**
     * Test that relation fields resolve with loaded relations.
     *
     * @return void
     */
    public function testRelationFieldsResolveWithLoadedRelations(): void
    {
        $org = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = User::create([
            'name'            => 'Related',
            'email'           => 'related@example.com',
            'organization_id' => $org->id,
        ]);

        $user->load('organization');

        $resource = new UserResource($user);
        $resource->withFields(['name', 'organization']);

        $result = $resource->resolve();

        static::assertArrayHasKey('organization', $result);
        static::assertInstanceOf(\SineMacula\ApiToolkit\Http\Resources\ApiResource::class, $result['organization']);

        $nested = $result['organization']->resolve();

        static::assertSame('organizations', $nested['_type']);
    }

    /**
     * Test that relation fields return MissingValue when not loaded.
     *
     * @return void
     */
    public function testRelationFieldsReturnMissingValueWhenNotLoaded(): void
    {
        $org = Organization::create([
            'name' => 'Unloaded Corp',
            'slug' => 'unloaded',
        ]);

        $user = User::create([
            'name'            => 'NoRelation',
            'email'           => 'norel@example.com',
            'organization_id' => $org->id,
        ]);

        $resource = new UserResource($user);
        $resource->withFields(['name', 'organization']);

        $result = $resource->resolve();

        static::assertArrayNotHasKey('organization', $result);
    }

    /**
     * Test that relation with accessor resolves to accessor value.
     *
     * @return void
     */
    public function testRelationWithAccessorResolvesToAccessorValue(): void
    {
        $user = User::create([
            'name'  => 'ProfileUser',
            'email' => 'profile@example.com',
        ]);

        $profile = Profile::create([
            'user_id' => $user->id,
            'bio'     => 'A great bio',
        ]);

        $user->load('profile');

        $resource = new UserResource($user);
        $resource->withFields(['name', 'profile_bio']);

        $result = $resource->resolve();

        static::assertArrayHasKey('profile_bio', $result);
        static::assertSame('A great bio', $result['profile_bio']);
    }

    /**
     * Test that relation returns null when loaded but related is null.
     *
     * @return void
     */
    public function testRelationReturnsNullWhenLoadedButRelatedIsNull(): void
    {
        $user = User::create([
            'name'  => 'NoOrg',
            'email' => 'noorg@example.com',
        ]);

        $user->load('organization');

        $resource = new UserResource($user);
        $resource->withFields(['name', 'organization']);

        $result = $resource->resolve();

        static::assertArrayHasKey('organization', $result);
        static::assertNull($result['organization']);
    }

    /**
     * Test that counts payload is included when requested.
     *
     * @return void
     */
    public function testCountsPayloadIsIncludedWhenRequested(): void
    {
        $user = User::create([
            'name'  => 'Counter',
            'email' => 'counter@example.com',
        ]);

        Post::create([
            'user_id'   => $user->id,
            'title'     => 'First Post',
            'body'      => 'Content',
            'published' => true,
        ]);

        Post::create([
            'user_id'   => $user->id,
            'title'     => 'Second Post',
            'body'      => 'More content',
            'published' => false,
        ]);

        $user->loadCount('posts');

        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => ['users' => 'id,name,counts'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        static::assertArrayHasKey('counts', $result);
        static::assertArrayHasKey('posts', $result['counts']);
        static::assertSame(2, $result['counts']['posts']);
    }

    /**
     * Test that counts are included when default flag is set.
     *
     * @return void
     */
    public function testCountsIncludedWhenDefaultFlagIsSet(): void
    {
        $user = User::create([
            'name'  => 'DefaultCount',
            'email' => 'defcount@example.com',
        ]);

        $user->loadCount('posts');

        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => ['users' => 'id,name,counts'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        static::assertArrayHasKey('counts', $result);
        static::assertArrayHasKey('posts', $result['counts']);
    }

    /**
     * Test that eagerLoadMapFor builds correct eager-load map.
     *
     * @return void
     */
    public function testEagerLoadMapForBuildsCorrectMap(): void
    {
        $fields = ['id', 'name', 'organization'];

        $map = UserResource::eagerLoadMapFor($fields);

        static::assertContains('organization', $map);
    }

    /**
     * Test that eagerLoadMapFor returns empty array when no relations.
     *
     * @return void
     */
    public function testEagerLoadMapForReturnsEmptyWhenNoRelations(): void
    {
        $fields = ['id', 'name', 'email'];

        $map = UserResource::eagerLoadMapFor($fields);

        static::assertSame([], $map);
    }

    /**
     * Test that eagerLoadMapFor with constrained relations returns closures.
     *
     * @return void
     */
    public function testEagerLoadMapForWithConstrainedRelationsReturnClosures(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'constrained_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'items'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('items', TagResource::class)->constrain(fn ($query) => $query->where('active', true)),
                );
            }
        };

        $fields = ['items'];

        $map = $resource_class::eagerLoadMapFor($fields);

        static::assertArrayHasKey('items', $map);
        static::assertIsCallable($map['items']);
    }

    /**
     * Test that eagerLoadCountsFor builds correct count map.
     *
     * @return void
     */
    public function testEagerLoadCountsForBuildsCorrectCountMap(): void
    {
        $counts = UserResource::eagerLoadCountsFor(['posts']);

        static::assertContains('posts', $counts);
    }

    /**
     * Test that eagerLoadCountsFor respects default flag.
     *
     * @return void
     */
    public function testEagerLoadCountsForRespectsDefaultFlag(): void
    {
        $counts = UserResource::eagerLoadCountsFor(null);

        static::assertContains('posts', $counts);
    }

    /**
     * Test that eagerLoadCountsFor with no defaults returns empty.
     *
     * @return void
     */
    public function testEagerLoadCountsForWithNoDefaultsReturnsEmpty(): void
    {
        $counts = OrganizationResource::eagerLoadCountsFor(null);

        static::assertSame([], $counts);
    }

    /**
     * Test that eagerLoadCountsFor with explicit request returns requested.
     *
     * @return void
     */
    public function testEagerLoadCountsForWithExplicitRequestReturnsRequested(): void
    {
        $counts = OrganizationResource::eagerLoadCountsFor(['users']);

        static::assertContains('users', $counts);
    }

    /**
     * Test that newCollection returns ApiResourceCollection.
     *
     * @return void
     */
    public function testNewCollectionReturnsApiResourceCollection(): void
    {
        $collection = UserResource::collection(collect([]));

        static::assertInstanceOf(ApiResourceCollection::class, $collection);
    }

    /**
     * Test that schema caching returns same result on second call.
     *
     * @return void
     */
    public function testSchemaCachingReturnsSameResultOnSecondCall(): void
    {
        $first  = UserResource::getAllFields();
        $second = UserResource::getAllFields();

        static::assertSame($first, $second);
    }

    /**
     * Test that field ordering follows default strategy.
     *
     * @return void
     */
    public function testFieldOrderingWithDefaultStrategy(): void
    {
        $user = User::create([
            'name'   => 'Ordered',
            'email'  => 'ordered@example.com',
            'status' => 'active',
        ]);

        $resource = new UserResource($user);
        $resource->withFields(['email', 'status', 'id', 'name']);

        $result = $resource->resolve();
        $keys   = array_keys($result);

        $type_index = array_search('_type', $keys, true);
        $id_index   = array_search('id', $keys, true);

        static::assertSame(0, $type_index, '_type should be first');
        static::assertSame(1, $id_index, 'id should be second');
    }

    /**
     * Test that withFields overrides API query fields.
     *
     * @return void
     */
    public function testWithFieldsOverridesApiQueryFields(): void
    {
        $user = User::create([
            'name'   => \Override::class,
            'email'  => 'override@example.com',
            'status' => 'active',
        ]);

        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => ['users' => 'id,name,email,status'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $resource->withFields(['name']);

        $result = $resource->resolve();

        static::assertArrayHasKey('name', $result);
        static::assertArrayNotHasKey('status', $result);
    }

    /**
     * Test that withoutFields removes specified fields from output.
     *
     * @return void
     */
    public function testWithoutFieldsRemovesSpecifiedFields(): void
    {
        $user = User::create([
            'name'   => 'Excluded',
            'email'  => 'excluded@example.com',
            'status' => 'active',
        ]);

        $resource = new UserResource($user);
        $resource->withoutFields(['name']);

        $result = $resource->resolve();

        static::assertArrayNotHasKey('name', $result);
        static::assertArrayHasKey('email', $result);
    }

    /**
     * Test that resolve includes simple properties from model attributes.
     *
     * @return void
     */
    public function testResolveIncludesSimplePropertiesFromModelAttributes(): void
    {
        $user = User::create([
            'name'   => 'Simple',
            'email'  => 'simple@example.com',
            'status' => 'active',
        ]);

        $resource = new UserResource($user);
        $resource->withFields(['name', 'email']);

        $result = $resource->resolve();

        static::assertSame('Simple', $result['name']);
        static::assertSame('simple@example.com', $result['email']);
    }

    /**
     * Test constructor with :all string sets withAll flag.
     *
     * @return void
     */
    public function testConstructorWithAllStringSetsWithAllFlag(): void
    {
        $user = User::create([
            'name'   => 'AllConstructor',
            'email'  => 'allcon@example.com',
            'status' => 'active',
        ]);

        $resource = new UserResource($user, false, ':all');
        $result   = $resource->resolve();

        static::assertArrayHasKey('name', $result);
        static::assertArrayHasKey('email', $result);
        static::assertArrayHasKey('status', $result);
        static::assertArrayHasKey('full_label', $result);
    }

    /**
     * Test constructor with included fields array.
     *
     * @return void
     */
    public function testConstructorWithIncludedFieldsArray(): void
    {
        $user = User::create([
            'name'  => 'Included',
            'email' => 'included@example.com',
        ]);

        $resource = new UserResource($user, false, ['name']);
        $result   = $resource->resolve();

        static::assertArrayHasKey('name', $result);
        static::assertArrayNotHasKey('email', $result);
    }

    /**
     * Test constructor with excluded fields array.
     *
     * @return void
     */
    public function testConstructorWithExcludedFieldsArray(): void
    {
        $user = User::create([
            'name'  => 'Excluded',
            'email' => 'excluded@example.com',
        ]);

        $resource = new UserResource($user, false, null, ['email']);
        $result   = $resource->resolve();

        static::assertArrayNotHasKey('email', $result);
        static::assertArrayHasKey('name', $result);
    }

    /**
     * Test constructor with load_missing triggers eager loading.
     *
     * @return void
     */
    public function testConstructorWithLoadMissingTriggersEagerLoading(): void
    {
        $org = Organization::create([
            'name' => 'Eager Corp',
            'slug' => 'eager',
        ]);

        $user = User::create([
            'name'            => 'Eager',
            'email'           => 'eager@example.com',
            'organization_id' => $org->id,
        ]);

        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => ['users' => 'id,name,organization'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user, true);
        $result   = $resource->resolve();

        static::assertArrayHasKey('organization', $result);
        static::assertInstanceOf(\SineMacula\ApiToolkit\Http\Resources\ApiResource::class, $result['organization']);

        $nested = $result['organization']->resolve();

        static::assertSame('organizations', $nested['_type']);
    }

    /**
     * Test that eagerLoadMapFor recurses into child resources.
     *
     * @return void
     */
    public function testEagerLoadMapForRecursesIntoChildResources(): void
    {
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', 'GET', [
            'fields' => [
                'users' => 'id,posts',
                'posts' => 'id,title,tags',
            ],
        ]);

        $parser->parse($request);

        $fields = ['posts'];
        $map    = UserResource::eagerLoadMapFor($fields);

        static::assertContains('posts', $map);
        static::assertContains('posts.tags', $map);
    }

    /**
     * Test that multiple transformers are applied in order.
     *
     * @return void
     */
    public function testMultipleTransformersAppliedInOrder(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'multi_transform_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::scalar('name')
                        ->transform(fn ($resource, $value) => strtoupper($value))
                        ->transform(fn ($resource, $value) => 'PREFIX_' . $value),
                );
            }
        };

        $user = User::create([
            'name'  => 'multi',
            'email' => 'multi@example.com',
        ]);

        $resource = new $resource_class($user);
        $resource->withFields(['name']);

        $result = $resource->resolve();

        static::assertSame('PREFIX_MULTI', $result['name']);
    }

    /**
     * Test that resolve handles non-object resource gracefully.
     *
     * @return void
     */
    public function testResolveHandlesNonObjectResourceGracefully(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'null_test';

            /** @var array<int, string> */
            protected static array $default = ['id'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                );
            }
        };

        $resource = new $resource_class(null);
        $resource->withFields(['id']);

        $result = $resource->resolve();

        static::assertArrayHasKey('_type', $result);
        static::assertArrayNotHasKey('id', $result);
    }

    /**
     * Test that hasMany relation resolves as collection resource.
     *
     * @return void
     */
    public function testHasManyRelationResolvesAsCollectionResource(): void
    {
        $user = User::create([
            'name'  => 'HasMany',
            'email' => 'hasmany@example.com',
        ]);

        Post::create([
            'user_id'   => $user->id,
            'title'     => 'Post One',
            'body'      => 'Body one',
            'published' => true,
        ]);

        Post::create([
            'user_id'   => $user->id,
            'title'     => 'Post Two',
            'body'      => 'Body two',
            'published' => false,
        ]);

        $user->load('posts');

        $resource = new UserResource($user);
        $resource->withFields(['name', 'posts']);

        $result = $resource->resolve();

        static::assertArrayHasKey('posts', $result);
    }

    /**
     * Test that withFields returns fluent instance.
     *
     * @return void
     */
    public function testWithFieldsReturnsFluent(): void
    {
        $user = User::create([
            'name'  => 'Fluent',
            'email' => 'fluent@example.com',
        ]);

        $resource = new UserResource($user);
        $result   = $resource->withFields(['name']);

        static::assertSame($resource, $result);
    }

    /**
     * Test that withoutFields returns fluent instance.
     *
     * @return void
     */
    public function testWithoutFieldsReturnsFluent(): void
    {
        $user = User::create([
            'name'  => 'Fluent',
            'email' => 'fluent@example.com',
        ]);

        $resource = new UserResource($user);
        $result   = $resource->withoutFields(['name']);

        static::assertSame($resource, $result);
    }

    /**
     * Test that withAll returns fluent instance.
     *
     * @return void
     */
    public function testWithAllReturnsFluent(): void
    {
        $user = User::create([
            'name'  => 'Fluent',
            'email' => 'fluent@example.com',
        ]);

        $resource = new UserResource($user);
        $result   = $resource->withAll();

        static::assertSame($resource, $result);
    }

    /**
     * Test eagerLoadCountsFor with constrained count returns closure.
     *
     * @return void
     */
    public function testEagerLoadCountsForWithConstrainedCountReturnsClosure(): void
    {
        $resource_class = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'constrained_count_test';

            /** @var array<int, string> */
            protected static array $default = ['id'];

            /**
             * Get the resource schema.
             *
             * @return array
             */
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Count::of('items')->constrain(fn ($query) => $query->where('active', true))->default(),
                );
            }
        };

        $counts = $resource_class::eagerLoadCountsFor(null);

        static::assertArrayHasKey('items', $counts);
        static::assertIsCallable($counts['items']);
    }

    /**
     * Test that timestamps are ordered at the end in default strategy.
     *
     * @return void
     */
    public function testTimestampsAreOrderedAtEndInDefaultStrategy(): void
    {
        $user = User::create([
            'name'   => 'TimeOrder',
            'email'  => 'timeorder@example.com',
            'status' => 'active',
        ]);

        $resource = new UserResource($user);
        $resource->withFields(['created_at', 'name', 'email', 'updated_at', 'status']);

        $result = $resource->resolve();
        $keys   = array_keys($result);

        $name_index       = array_search('name', $keys, true);
        $created_at_index = array_search('created_at', $keys, true);
        $updated_at_index = array_search('updated_at', $keys, true);

        static::assertGreaterThan($name_index, $created_at_index);
        static::assertGreaterThan($name_index, $updated_at_index);
    }

    /**
     * Test that toArray delegates to resolve.
     *
     * @return void
     */
    public function testToArrayDelegatesToResolve(): void
    {
        $user = User::create([
            'name'  => 'ToArray',
            'email' => 'toarray@example.com',
        ]);

        $resource = new UserResource($user);
        $request  = Request::create('/', 'GET');

        $resolved = $resource->resolve($request);
        $array    = $resource->toArray($request);

        static::assertSame($resolved, $array);
    }

    /**
     * Test that different resource types produce correct types.
     *
     * @param  string  $resource_class
     * @param  string  $expected_type
     * @return void
     */
    #[DataProvider('resourceTypeProvider')]
    public function testDifferentResourceTypesProduceCorrectTypes(string $resource_class, string $expected_type): void
    {
        static::assertSame($expected_type, $resource_class::getResourceType());
    }

    /**
     * Provide resource class and expected type pairs.
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function resourceTypeProvider(): iterable
    {
        yield 'user resource' => [UserResource::class, 'users'];
        yield 'organization resource' => [OrganizationResource::class, 'organizations'];
        yield 'post resource' => [PostResource::class, 'posts'];
        yield 'tag resource' => [TagResource::class, 'tags'];
    }

    /**
     * Test that getAllFields for different resources returns correct fields.
     *
     * @param  string  $resource_class
     * @param  array  $expected_fields
     * @return void
     */
    #[DataProvider('allFieldsProvider')]
    public function testGetAllFieldsForDifferentResources(string $resource_class, array $expected_fields): void
    {
        $all_fields = $resource_class::getAllFields();

        foreach ($expected_fields as $field) {
            static::assertContains($field, $all_fields);
        }
    }

    /**
     * Provide resource classes with expected field lists.
     *
     * @return iterable<string, array{class-string, array<int, string>}>
     */
    public static function allFieldsProvider(): iterable
    {
        yield 'organization fields' => [OrganizationResource::class, ['id', 'name', 'slug', 'created_at', 'updated_at']];
        yield 'post fields' => [PostResource::class, ['id', 'title', 'body', 'published', 'created_at', 'updated_at', 'user', 'tags']];
        yield 'tag fields' => [TagResource::class, ['id', 'name', 'created_at', 'updated_at']];
    }

    /**
     * Clear the static schema cache between tests.
     *
     * @return void
     */
    private function clearSchemaCache(): void
    {
        $property = new \ReflectionProperty(ApiResource::class, 'schemaCache');
        $property->setValue(null, []);
    }
}
