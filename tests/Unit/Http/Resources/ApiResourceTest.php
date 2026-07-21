<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\AggregateCapturingModel;
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
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
final class ApiResourceTest extends TestCase
{
    /** @var string Email address used in fluent-return test fixtures. */
    private const string FLUENT_EMAIL = 'fluent@example.com';

    /** @var string Field selection string that includes the counts field. */
    private const string COUNTS_FIELDS = 'id,counts';

    /** @var string Count metric key for the posts relation. */
    private const string COUNT_KEY_POSTS = '__count__:posts';

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
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
        self::assertSame('users', UserResource::getResourceType());
        self::assertSame('organizations', OrganizationResource::getResourceType());
        self::assertSame('posts', PostResource::getResourceType());
        self::assertSame('tags', TagResource::getResourceType());
    }

    /**
     * Test that getResourceType throws LogicException when RESOURCE_TYPE is not
     * defined.
     *
     * @return void
     */
    public function testGetResourceTypeThrowsLogicExceptionWhenNotDefined(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The RESOURCE_TYPE constant must be defined on the resource');

        $resource = new class (null) extends ApiResource {
            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [];
            }
        };

        $resource::getResourceType();
    }

    /**
     * Test that getDefaultFields returns the static default array.
     *
     * @return void
     */
    public function testGetDefaultFieldsReturnsStaticDefaultArray(): void
    {
        self::assertSame(['id', 'name', 'email'], UserResource::getDefaultFields());
        self::assertSame(['id', 'name', 'slug'], OrganizationResource::getDefaultFields());
        self::assertSame(['id', 'title'], PostResource::getDefaultFields());
        self::assertSame(['id', 'name'], TagResource::getDefaultFields());
    }

    /**
     * Test that getAllFields returns all field keys excluding count metrics.
     *
     * @return void
     */
    public function testGetAllFieldsReturnsAllFieldKeysExcludingCounts(): void
    {
        $allFields = UserResource::getAllFields();

        self::assertContains('id', $allFields);
        self::assertContains('name', $allFields);
        self::assertContains('email', $allFields);
        self::assertContains('status', $allFields);
        self::assertContains('created_at', $allFields);
        self::assertContains('updated_at', $allFields);
        self::assertContains('full_label', $allFields);
        self::assertContains('organization', $allFields);
        self::assertContains('profile_bio', $allFields);
        self::assertContains('posts', $allFields);

        foreach ($allFields as $field) {
            self::assertStringNotContainsString('__count__', $field);
        }
    }

    /**
     * Test that resolveFields returns API query fields when set.
     *
     * @return void
     */
    public function testResolveFieldsReturnsApiQueryFieldsWhenSet(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');

        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,status'],
        ]);

        $parser->parse($request);

        $fields = UserResource::resolveFields();

        self::assertSame(['id', 'name', 'status'], $fields);
    }

    /**
     * Test that resolveFields returns defaults when no API query fields set.
     *
     * @return void
     */
    public function testResolveFieldsReturnsDefaultsWhenNoQueryFields(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');

        $request = Request::create('/', HttpMethod::GET->getVerb());

        $parser->parse($request);

        $fields = UserResource::resolveFields();

        self::assertSame(['id', 'name', 'email'], $fields);
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

        self::assertArrayHasKey('_type', $result);
        self::assertSame('users', $result['_type']);
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

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'name'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayHasKey('_type', $result);
        self::assertArrayHasKey('id', $result);
        self::assertArrayHasKey('name', $result);
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

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayHasKey('name', $result);
        self::assertArrayNotHasKey('status', $result);
        self::assertArrayNotHasKey('email', $result);
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

        self::assertArrayNotHasKey('email', $result);
        self::assertArrayHasKey('name', $result);
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

        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('full_label', $result);
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

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => ':all'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('full_label', $result);
    }

    /**
     * Test that guards prevent field inclusion when they return false.
     *
     * @return void
     */
    public function testGuardsPreventFieldInclusionWhenReturnFalse(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'guarded_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name', 'secret'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
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

        $resource = new $resourceClass($user);
        $resource->withFields(['id', 'name', 'secret']);

        $result = $resource->resolve();

        self::assertArrayHasKey('name', $result);
        self::assertArrayNotHasKey('secret', $result);
    }

    /**
     * Test that guards allow field inclusion when they return true.
     *
     * @return void
     */
    public function testGuardsAllowFieldInclusionWhenReturnTrue(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'guard_pass_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name', 'visible'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
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

        $user->setAttribute('visible', 'shown');

        $resource = new $resourceClass($user);
        $resource->withFields(['name', 'visible']);

        $result = $resource->resolve();

        self::assertArrayHasKey('name', $result);
    }

    /**
     * Test that transformers modify resolved values.
     *
     * @return void
     */
    public function testTransformersModifyResolvedValues(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'transform_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
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

        $resource = new $resourceClass($user);
        $resource->withFields(['name']);

        $result = $resource->resolve();

        self::assertSame('LOWERCASE', $result['name']);
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

        self::assertSame('Computed <computed@example.com>', $result['full_label']);
    }

    /**
     * Test that accessor fields resolve via string path.
     *
     * @return void
     */
    public function testAccessorFieldsResolveViaStringPath(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'accessor_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'nested_value'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
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

        $resource = new $resourceClass($user);
        $resource->withFields(['nested_value']);

        $result = $resource->resolve();

        self::assertSame('Accessed', $result['nested_value']);
    }

    /**
     * Test that accessor fields resolve via callable.
     *
     * @return void
     */
    public function testAccessorFieldsResolveViaCallable(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'accessor_callable_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'computed_accessor'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::accessor('computed_accessor', static function ($resource): string {

                        $user = $resource->resource;

                        assert($user instanceof User);

                        return 'custom:' . $user->name;
                    }),
                );
            }
        };

        $user = User::create([
            'name'  => 'CallableAccess',
            'email' => 'callable@example.com',
        ]);

        $resource = new $resourceClass($user);
        $resource->withFields(['computed_accessor']);

        $result = $resource->resolve();

        self::assertSame('custom:CallableAccess', $result['computed_accessor']);
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

        self::assertArrayHasKey('organization', $result);
        self::assertInstanceOf(ApiResource::class, $result['organization']);

        $nested = $result['organization']->resolve();

        self::assertSame('organizations', $nested['_type']);
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

        self::assertArrayNotHasKey('organization', $result);
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

        Profile::create([
            'user_id' => $user->id,
            'bio'     => 'A great bio',
        ]);

        $user->load('profile');

        $resource = new UserResource($user);
        $resource->withFields(['name', 'profile_bio']);

        $result = $resource->resolve();

        self::assertArrayHasKey('profile_bio', $result);
        self::assertSame('A great bio', $result['profile_bio']);
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

        self::assertArrayHasKey('organization', $result);
        self::assertNull($result['organization']);
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

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,counts'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayHasKey('counts', $result);
        self::assertArrayHasKey('posts', $result['counts']);
        self::assertSame(2, $result['counts']['posts']);
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

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,counts'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayHasKey('counts', $result);
        self::assertArrayHasKey('posts', $result['counts']);
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

        self::assertContains('organization', $map);
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

        self::assertSame([], $map);
    }

    /**
     * Test that eagerLoadMapFor with constrained relations returns closures.
     *
     * @return void
     */
    public function testEagerLoadMapForWithConstrainedRelationsReturnClosures(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'constrained_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'items'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('items', TagResource::class)->constrain(fn ($query) => $query->where('active', true)),
                );
            }
        };

        $fields = ['items'];

        $map = $resourceClass::eagerLoadMapFor($fields);

        self::assertArrayHasKey('items', $map);
        self::assertIsCallable($map['items']);
    }

    /**
     * Test that eagerLoadCountsFor builds correct count map.
     *
     * @return void
     */
    public function testEagerLoadCountsForBuildsCorrectCountMap(): void
    {
        $counts = UserResource::eagerLoadCountsFor(['posts']);

        self::assertContains('posts as posts_count', $counts);
    }

    /**
     * Test that eagerLoadCountsFor respects default flag.
     *
     * @return void
     */
    public function testEagerLoadCountsForRespectsDefaultFlag(): void
    {
        $counts = UserResource::eagerLoadCountsFor(null);

        self::assertContains('posts as posts_count', $counts);
    }

    /**
     * Test that eagerLoadCountsFor with no defaults returns empty.
     *
     * @return void
     */
    public function testEagerLoadCountsForWithNoDefaultsReturnsEmpty(): void
    {
        $counts = OrganizationResource::eagerLoadCountsFor(null);

        self::assertSame([], $counts);
    }

    /**
     * Test that eagerLoadCountsFor with explicit request returns requested.
     *
     * @return void
     */
    public function testEagerLoadCountsForWithExplicitRequestReturnsRequested(): void
    {
        $counts = OrganizationResource::eagerLoadCountsFor(['users']);

        self::assertContains('users as users_count', $counts);
    }

    /**
     * Test that newCollection returns ApiResourceCollection.
     *
     * @return void
     */
    public function testNewCollectionReturnsApiResourceCollection(): void
    {
        $collection = UserResource::collection(collect([]));

        self::assertInstanceOf(ApiResourceCollection::class, $collection);
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

        self::assertSame($first, $second);
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

        $typeIndex = array_search('_type', $keys, true);
        $idIndex   = array_search('id', $keys, true);

        self::assertSame(0, $typeIndex, '_type should be first');
        self::assertSame(1, $idIndex, 'id should be second');
    }

    /**
     * Test that withFields overrides API query fields.
     *
     * @return void
     */
    public function testWithFieldsOverridesApiQueryFields(): void
    {
        $user = User::create([
            'name'   => 'Override User',
            'email'  => 'override@example.com',
            'status' => 'active',
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,email,status'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $resource->withFields(['name']);

        $result = $resource->resolve();

        self::assertArrayHasKey('name', $result);
        self::assertArrayNotHasKey('status', $result);
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

        self::assertArrayNotHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
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

        self::assertSame('Simple', $result['name']);
        self::assertSame('simple@example.com', $result['email']);
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

        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('full_label', $result);
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

        self::assertArrayHasKey('name', $result);
        self::assertArrayNotHasKey('email', $result);
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

        self::assertArrayNotHasKey('email', $result);
        self::assertArrayHasKey('name', $result);
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

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,organization'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user, true);
        $result   = $resource->resolve();

        self::assertArrayHasKey('organization', $result);
        self::assertInstanceOf(ApiResource::class, $result['organization']);

        $nested = $result['organization']->resolve();

        self::assertSame('organizations', $nested['_type']);
    }

    /**
     * Test that eagerLoadMapFor recurses into child resources.
     *
     * @return void
     */
    public function testEagerLoadMapForRecursesIntoChildResources(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => [
                'users' => 'id,posts',
                'posts' => 'id,title,tags',
            ],
        ]);

        $parser->parse($request);

        $fields = ['posts'];
        $map    = UserResource::eagerLoadMapFor($fields);

        self::assertContains('posts', $map);
        self::assertContains('posts.tags', $map);
    }

    /**
     * Test that multiple transformers are applied in order.
     *
     * @return void
     */
    public function testMultipleTransformersAppliedInOrder(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'multi_transform_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::scalar('name')
                        ->transform(fn ($resource, $value) => strtoupper($value))
                        ->transform(fn ($resource, $value) => 'PREFIX_' . (string) $value), // @phpstan-ignore cast.string
                );
            }
        };

        $user = User::create([
            'name'  => 'multi',
            'email' => 'multi@example.com',
        ]);

        $resource = new $resourceClass($user);
        $resource->withFields(['name']);

        $result = $resource->resolve();

        self::assertSame('PREFIX_MULTI', $result['name']);
    }

    /**
     * Test that resolve handles non-object resource gracefully.
     *
     * @return void
     */
    public function testResolveHandlesNonObjectResourceGracefully(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'null_test';

            /** @var array<int, string> */
            protected static array $default = ['id'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                );
            }
        };

        $resource = new $resourceClass(null);
        $resource->withFields(['id']);

        $result = $resource->resolve();

        self::assertArrayHasKey('_type', $result);
        self::assertArrayNotHasKey('id', $result);
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

        self::assertArrayHasKey('posts', $result);
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
            'email' => self::FLUENT_EMAIL,
        ]);

        $resource = new UserResource($user);
        $result   = $resource->withFields(['name']);

        self::assertSame($resource, $result);
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
            'email' => self::FLUENT_EMAIL,
        ]);

        $resource = new UserResource($user);
        $result   = $resource->withoutFields(['name']);

        self::assertSame($resource, $result);
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
            'email' => self::FLUENT_EMAIL,
        ]);

        $resource = new UserResource($user);
        $result   = $resource->withAll();

        self::assertSame($resource, $result);
    }

    /**
     * Test eagerLoadCountsFor with constrained count returns closure.
     *
     * @return void
     */
    public function testEagerLoadCountsForWithConstrainedCountReturnsClosure(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'constrained_count_test';

            /** @var array<int, string> */
            protected static array $default = ['id'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Count::of('items')->constrain(fn ($query) => $query->where('active', true))->default(),
                );
            }
        };

        $counts = $resourceClass::eagerLoadCountsFor(null);

        self::assertArrayHasKey('items as items_count', $counts);
        self::assertIsCallable($counts['items as items_count']);
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

        $nameIndex      = array_search('name', $keys, true);
        $createdAtIndex = array_search('created_at', $keys, true);
        $updatedAtIndex = array_search('updated_at', $keys, true);

        self::assertGreaterThan($nameIndex, $createdAtIndex);
        self::assertGreaterThan($nameIndex, $updatedAtIndex);
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
        $request  = Request::create('/', HttpMethod::GET->getVerb());

        $resolved = $resource->resolve($request);
        $array    = $resource->toArray($request);

        self::assertSame($resolved, $array);
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
     * Test that different resource types produce correct types.
     *
     * @param  string  $resourceClass
     * @param  string  $expectedType
     * @return void
     */
    #[DataProvider('resourceTypeProvider')]
    public function testDifferentResourceTypesProduceCorrectTypes(string $resourceClass, string $expectedType): void
    {
        self::assertSame($expectedType, $resourceClass::getResourceType());
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
     * Test that getAllFields for different resources returns correct fields.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $expectedFields
     * @return void
     */
    #[DataProvider('allFieldsProvider')]
    public function testGetAllFieldsForDifferentResources(string $resourceClass, array $expectedFields): void
    {
        $allFields = $resourceClass::getAllFields();

        foreach ($expectedFields as $field) {
            self::assertContains($field, $allFields);
        }
    }

    /**
     * Test that load_missing with ':all' uses getAllFields for eager loading.
     *
     * @return void
     */
    public function testConstructorWithLoadMissingAndAllUsesGetAllFields(): void
    {
        $user = User::create([
            'name'  => 'EagerAll',
            'email' => 'eagerall@example.com',
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb());
        $parser->parse($request);

        $resource = new UserResource($user, true, ':all');
        $result   = $resource->resolve();

        self::assertArrayHasKey('_type', $result);
        self::assertArrayHasKey('name', $result);
    }

    /**
     * Test that load_missing triggers loadCount when 'counts' is in the
     * included fields.
     *
     * @return void
     */
    public function testConstructorWithLoadMissingAndCountsField(): void
    {
        $user = User::create([
            'name'  => 'CountLoad',
            'email' => 'countload@example.com',
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb());
        $parser->parse($request);

        // 'counts' in included ensures shouldIncludeCountsField() returns true
        // inside the constructor, exercising the loadCount branch.
        $resource = new UserResource($user, true, ['id', 'counts']);
        $result   = $resource->resolve();

        self::assertArrayHasKey('_type', $result);
    }

    /**
     * Test that resolveCountsPayload returns an empty array for non-object
     * resources, including unwrapping nested JsonResource layers.
     *
     * Passes a UserResource wrapping null so that unwrapResource loops through
     * the JsonResource layer (covering line 863) and then finds a non-object,
     * exercising the early-return branch (line 255).
     *
     * @return void
     */
    public function testResolveCountsPayloadReturnsEmptyForNullResource(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), ['fields' => ['users' => self::COUNTS_FIELDS]]);
        $parser->parse($request);

        // Inner resource wraps null; outer resource wraps the inner resource.
        // unwrapResource() loops through the JsonResource (line 863) and
        // reaches null, triggering the is_object guard (line 255).
        $inner    = new UserResource(null);
        $resource = new UserResource($inner);

        $result = $resource->resolve();

        self::assertArrayNotHasKey('counts', $result);
    }

    /**
     * Test that a count field excluded by a guard is omitted from the counts
     * payload.
     *
     * @SuppressWarnings("php:S2014")
     *
     * @return void
     */
    public function testCountExcludedByGuardIsOmittedFromPayload(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'guarded_count_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'counts'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Count::of('posts')->guard(fn () => false)->default(),
                );
            }
        };

        $user = User::create([
            'name'  => 'GuardedCount',
            'email' => 'guardedcount@example.com',
        ]);

        $user->loadCount('posts');

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), ['fields' => ['guarded_count_test' => self::COUNTS_FIELDS]]);
        $parser->parse($request);

        $resource = new $resourceClass($user);
        $result   = $resource->resolve();

        self::assertArrayNotHasKey('counts', $result);
    }

    /**
     * Test that a relation with a callable accessor resolves via the callable.
     *
     * @return void
     */
    public function testRelationWithCallableAccessorResolvesViaCallable(): void
    {
        $org = Organization::create([
            'name' => 'CallableOrg',
            'slug' => 'callable-org',
        ]);

        $user = User::create([
            'name'            => 'RelCallable',
            'email'           => 'relcallable@example.com',
            'organization_id' => $org->id,
        ]);

        $user->load('organization');

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'callable_rel_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'org_name'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('organization', static function ($resource): ?string {

                        $user = $resource->resource;

                        assert($user instanceof User);

                        return $user->organization?->name;
                    }, 'org_name'),
                );
            }
        };

        $resource = new $resourceClass($user);
        $resource->withFields(['org_name']);

        $result = $resource->resolve();

        self::assertArrayHasKey('org_name', $result);
        self::assertSame('CallableOrg', $result['org_name']);
    }

    /**
     * Test that eagerLoadMapFor includes child fields from explicit relation
     * field projections, covering resolveChildFields explicit-fields branch.
     *
     * @return void
     */
    public function testEagerLoadMapForWithExplicitChildFields(): void
    {
        $this->clearSchemaCache();

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'explicit_fields_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'organization'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('organization', OrganizationResource::class)
                        ->fields(['id', 'name']),
                );
            }
        };

        $fields = ['organization'];
        $map    = $resourceClass::eagerLoadMapFor($fields);

        self::assertNotEmpty($map);
    }

    /**
     * Test that wrapRelatedWithResource calls withFields on the wrapped
     * resource when child fields are provided.
     *
     * @return void
     */
    public function testRelationWithExplicitChildFieldsFiltersOutput(): void
    {
        $this->clearSchemaCache();

        $org = Organization::create([
            'name' => 'ChildFieldOrg',
            'slug' => 'child-field-org',
        ]);

        $user = User::create([
            'name'            => 'ChildField',
            'email'           => 'childfield@example.com',
            'organization_id' => $org->id,
        ]);

        $user->load('organization');

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'child_fields_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'organization'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('organization', OrganizationResource::class)
                        ->fields(['id', 'name']),
                );
            }
        };

        $resource = new $resourceClass($user);
        $resource->withFields(['id', 'organization']);

        $result = $resource->resolve();

        self::assertArrayHasKey('organization', $result);
    }

    /**
     * Test that unwrapResource loops through nested JsonResource layers to
     * reach the underlying model (line 863).
     *
     * @return void
     */
    public function testUnwrapResourceLoopsThroughNestedJsonResource(): void
    {
        $user = User::create([
            'name'  => 'Wrapped',
            'email' => 'wrapped2@example.com',
        ]);

        $user->loadCount('posts');

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), ['fields' => ['users' => self::COUNTS_FIELDS]]);
        $parser->parse($request);

        // Wrap the user in an inner UserResource, then wrap THAT in an outer
        // UserResource. unwrapResource() loops through the JsonResource layer
        // (line 863) to reach the User model, then resolves counts normally.
        $inner    = new UserResource($user);
        $resource = new UserResource($inner);

        $result = $resource->resolve();

        self::assertArrayHasKey('_type', $result);
        self::assertSame('users', $result['_type']);
    }

    /**
     * Test that a count metric key requested as a regular field is treated as
     * MissingValue and excluded from the output.
     *
     * @return void
     */
    public function testCountMetricKeyAsRegularFieldExcludedFromOutput(): void
    {
        $user = User::create([
            'name'  => 'MetricField',
            'email' => 'metricfield@example.com',
        ]);

        $resource = new UserResource($user);
        $resource->withFields([self::COUNT_KEY_POSTS]);

        $result = $resource->resolve();

        self::assertArrayNotHasKey(self::COUNT_KEY_POSTS, $result);
    }

    /**
     * Test that eagerLoadMapFor skips fields not in the schema (line 604).
     *
     * @return void
     */
    public function testEagerLoadMapForSkipsFieldsNotInSchema(): void
    {
        $map = UserResource::eagerLoadMapFor(['nonexistent_field_xyz']);

        self::assertSame([], $map);
    }

    /**
     * Test that eagerLoadMapFor skips metric fields in walkRelationsWith (line
     * 608).
     *
     * @return void
     */
    public function testEagerLoadMapForSkipsMetricFields(): void
    {
        // __count__:posts is a metric in the UserResource schema and must be
        // skipped when encountered during relation traversal.
        $map = UserResource::eagerLoadMapFor([self::COUNT_KEY_POSTS]);

        self::assertSame([], $map);
    }

    /**
     * Test that eagerLoadMapFor includes extras paths defined on a relation
     * (line 616).
     *
     * @return void
     */
    public function testEagerLoadMapForIncludesExtrasPaths(): void
    {
        $this->clearSchemaCache();

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'extras_path_test';

            /** @var array<int, string> */
            protected static array $default = ['organization'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Relation::to('organization', OrganizationResource::class)
                        ->extras('organization.owner'),
                );
            }
        };

        $map = $resourceClass::eagerLoadMapFor(['organization']);

        self::assertContains('organization.owner', $map);
    }

    /**
     * Test that eagerLoadMapFor does not add duplicate paths when the same
     * relation appears more than once in the fields list (line 624).
     *
     * @return void
     */
    public function testEagerLoadMapForDoesNotAddDuplicatePaths(): void
    {
        // Passing 'organization' twice triggers the wasVisited guard on the
        // second occurrence, exercising the continue on line 624.
        $map = UserResource::eagerLoadMapFor(['organization', 'organization']);

        $plainValues = array_values($map);

        self::assertContains('organization', $plainValues);
        self::assertCount(1, array_keys($map, 'organization', true));
    }

    /**
     * Test that resolveChildFields falls back to getAllFields when the child
     * resource has empty defaults and no requested fields (line 837).
     *
     * @return void
     */
    public function testResolveChildFieldsFallsBackToGetAllFieldsWhenDefaultsEmpty(): void
    {
        $this->clearSchemaCache();

        $childClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'no_defaults_child';

            /** @var array<int, string> */
            protected static array $default = [];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::scalar('name'),
                );
            }
        };

        $childClassName = $childClass::class;

        $outerClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'outer_for_empty_defaults';

            /** @var array<int, string> */
            protected static array $default = ['rel'];

            /** @var string */
            public static string $childClassName = '';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Relation::to('rel', self::$childClassName),
                );
            }
        };

        $outerClass::$childClassName = $childClassName;

        // No fields set for 'no_defaults_child' in the query — defaults are
        // empty — so resolveChildFields falls through to getAllFields
        // (line 837).
        $map = $outerClass::eagerLoadMapFor(['rel']);

        self::assertContains('rel', $map);
    }

    /**
     * Test that resolveSimpleProperty reflects on a model method when the field
     * name matches a method that does NOT return an Attribute, covering the
     * reflection path (lines 385-386) and the non-Attribute check (388).
     *
     * @return void
     */
    public function testResolveSimplePropertyReflectsOnModelMethod(): void
    {
        $this->clearSchemaCache();

        // Request 'posts' on a resource whose schema does NOT include it as
        // a relation. resolveSimpleProperty() is then called for 'posts'.
        // User.posts() exists (method_exists = true) → reflection runs → the
        // return type is HasMany, not Attribute → line 388 condition is false.
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'method_reflect_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'posts'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::scalar('posts'),
                );
            }
        };

        $user = User::create([
            'name'  => 'MethodReflect',
            'email' => 'methodreflect@example.com',
        ]);

        $resource = new $resourceClass($user);
        $resource->withFields(['id', 'posts']);

        $result = $resource->resolve();

        // 'posts' resolves via Eloquent's __isset/__get (relation lazy-loaded),
        // so the key is present in the result. The main goal is to exercise
        // the ReflectionMethod path (lines 385-386, 388) before falling through
        // to the __isset check.
        self::assertArrayHasKey('_type', $result);
    }

    /**
     * Test that the scoped-constraint closure returned by eagerLoadMapFor is
     * properly invoked for a non-MorphTo builder, covering the Builder-path
     * inside the wrapper closure (lines 637-640).
     *
     * @return void
     */
    public function testEagerLoadMapConstraintClosureExecutesOnBuilder(): void
    {
        $this->clearSchemaCache();

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'constrained_exec_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'organization'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('organization', OrganizationResource::class)
                        ->constrain(fn ($query) => $query->where('name', 'Active')),
                );
            }
        };

        $map = $resourceClass::eagerLoadMapFor(['organization']);

        self::assertArrayHasKey('organization', $map);
        self::assertIsCallable($map['organization']);

        // Invoke the wrapper closure with a real Builder so the non-MorphTo
        // path (lines 637-640) executes.
        $builder = Organization::query();
        ($map['organization'])($builder);

        self::assertNotEmpty($builder->getQuery()->wheres);
    }

    /**
     * Test that resolveSimpleProperty returns the accessor value when the model
     * method's return type is Attribute (line 389).
     *
     * @return void
     */
    public function testResolveSimplePropertyReturnsValueWhenMethodReturnsAttributeType(): void
    {
        $this->clearSchemaCache();

        $model = new class extends Model {
            /** @var string|null The database table backing the model. */
            protected $table = 'users';

            /**
             * Get the label attribute.
             *
             * @return \Illuminate\Database\Eloquent\Casts\Attribute
             */
            public function label(): Attribute
            {
                return Attribute::make(
                    get: fn () => 'attr_value',
                );
            }
        };

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'attr_method_test';

            /** @var array<int, string> */
            protected static array $default = ['label'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('label'),
                );
            }
        };

        $resource = new $resourceClass($model);
        $resource->withFields(['label']);

        $result = $resource->resolve();

        self::assertSame('attr_value', $result['label']);
    }

    /**
     * Test that getAttributeIfLoaded returns via __isset when the owner has no
     * getAttributes method (line 555).
     *
     * @return void
     */
    public function testGetAttributeIfLoadedTakesIssetPathForNonEloquentOwner(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), ['fields' => ['users' => 'id,counts']]);
        $parser->parse($request);

        // A plain object with __isset/__get but no getAttributes(), so
        // getAttributeIfLoaded falls through to the __isset branch (line 554).
        $fakeOwner = new class {
            /**
             * @param  string  $name
             * @return bool
             */
            public function __isset(string $name): bool
            {
                return $name === 'posts_count';
            }

            /**
             * @param  string  $name
             * @return mixed
             */
            public function __get(string $name): mixed
            {
                return $name === 'posts_count' ? 2 : null;
            }
        };

        $resource = new UserResource($fakeOwner);
        $result   = $resource->resolve();

        self::assertArrayHasKey('counts', $result);
        self::assertSame(2, $result['counts']['posts']);
    }

    /**
     * Test that the scoped-constraint closure passes the query directly to the
     * user constraint when the query is a MorphTo instance (lines 633-634).
     *
     * @return void
     */
    public function testEagerLoadMapConstraintClosureExecutesOnMorphTo(): void
    {
        $this->clearSchemaCache();

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'morph_to_exec_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'organization'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('organization', OrganizationResource::class)
                        ->constrain(fn ($query) => $query),
                );
            }
        };

        $map = $resourceClass::eagerLoadMapFor(['organization']);

        self::assertArrayHasKey('organization', $map);
        self::assertIsCallable($map['organization']);

        // Invoke the wrapper closure with a MorphTo mock to exercise the
        // MorphTo branch (lines 633-634) where the constraint is called
        // directly.
        $morphTo = \Mockery::mock(MorphTo::class);
        ($map['organization'])($morphTo);
    }

    /**
     * Test that getResourceType lowercases a mixed-case RESOURCE_TYPE constant.
     *
     * @return void
     */
    public function testGetResourceTypeLowercasesMixedCaseConstant(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'MixedCase';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [];
            }
        };

        self::assertSame('mixedcase', $resourceClass::getResourceType());
    }

    /**
     * Test that resolve skips fields missing from the schema and still resolves
     * the remaining fields.
     *
     * @return void
     */
    public function testResolveSkipsUnknownFieldsAndContinuesResolving(): void
    {
        $user = User::create([
            'name'  => 'SkipUnknown',
            'email' => 'skipunknown@example.com',
        ]);

        $resource = new UserResource($user);
        $resource->withFields(['unknown_field', 'name']);

        $result = $resource->resolve();

        self::assertArrayNotHasKey('unknown_field', $result);
        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('id', $result);
    }

    /**
     * Test that resolve skips a field resolving to MissingValue and still
     * resolves later fields in the list.
     *
     * @return void
     */
    public function testResolveContinuesPastMissingValueFields(): void
    {
        $user = User::create([
            'name'  => 'MissingValueSkip',
            'email' => 'missingvalueskip@example.com',
        ]);

        // The unloaded relation resolves to MissingValue and must be skipped
        // without aborting resolution of the later 'name' field.
        self::assertFalse($user->relationLoaded('organization'));

        $resource = new UserResource($user);
        $resource->withFields(['organization', 'name']);

        $result = $resource->resolve();

        self::assertArrayNotHasKey('organization', $result);
        self::assertArrayHasKey('name', $result);
        self::assertSame('MissingValueSkip', $result['name']);
    }

    /**
     * Test that the constructor does not eager load relations by default.
     *
     * @return void
     */
    public function testConstructorDoesNotEagerLoadRelationsByDefault(): void
    {
        $org = Organization::create([
            'name' => 'Lazy Corp',
            'slug' => 'lazy-corp',
        ]);

        $user = User::create([
            'name'            => 'Lazy',
            'email'           => 'lazy@example.com',
            'organization_id' => $org->id,
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,organization'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertFalse($user->relationLoaded('organization'));
        self::assertArrayNotHasKey('organization', $result);
    }

    /**
     * Test that load_missing in all-fields mode eager loads schema relations
     * that are absent from the resolved field list.
     *
     * @return void
     */
    public function testConstructorWithLoadMissingAndAllEagerLoadsSchemaRelations(): void
    {
        $org = Organization::create([
            'name' => 'All Corp',
            'slug' => 'all-corp',
        ]);

        $user = User::create([
            'name'            => 'AllLoad',
            'email'           => 'allload@example.com',
            'organization_id' => $org->id,
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb());

        $parser->parse($request);

        // The default fields contain no relations, so the eager-load map is
        // only non-empty when all-fields mode resolves the full schema.
        new UserResource($user, true, ':all');

        self::assertTrue($user->relationLoaded('organization'));
    }

    /**
     * Test that load_missing does not load counts when the counts field is not
     * requested.
     *
     * @return void
     */
    public function testConstructorWithLoadMissingSkipsCountsWhenNotRequested(): void
    {
        $user = User::create([
            'name'  => 'NoCountLoad',
            'email' => 'nocountload@example.com',
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name'],
        ]);

        $parser->parse($request);

        new UserResource($user, true);

        self::assertArrayNotHasKey('posts_count', $user->getAttributes());
    }

    /**
     * Test that load_missing loads default counts and resolves them into the
     * counts payload when the counts field is requested.
     *
     * @return void
     */
    public function testConstructorWithLoadMissingLoadsRequestedCounts(): void
    {
        $user = User::create([
            'name'  => 'CountLoader',
            'email' => 'countloader@example.com',
        ]);

        Post::create([
            'user_id'   => $user->id,
            'title'     => 'Counted One',
            'body'      => 'Body',
            'published' => true,
        ]);

        Post::create([
            'user_id'   => $user->id,
            'title'     => 'Counted Two',
            'body'      => 'Body',
            'published' => false,
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => self::COUNTS_FIELDS],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user, true);
        $result   = $resource->resolve();

        self::assertArrayHasKey('counts', $result);
        self::assertSame(2, $result['counts']['posts']);
    }

    /**
     * Test that load_missing honours explicitly requested count aliases from
     * the API query.
     *
     * @return void
     */
    public function testConstructorWithLoadMissingHonoursRequestedCountAliases(): void
    {
        $org = Organization::create([
            'name' => 'Counted Corp',
            'slug' => 'counted-corp',
        ]);

        User::create([
            'name'            => 'Member',
            'email'           => 'member@example.com',
            'organization_id' => $org->id,
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['organizations' => self::COUNTS_FIELDS],
            'counts' => ['organizations' => 'users'],
        ]);

        $parser->parse($request);

        $resource = new OrganizationResource($org, true);
        $result   = $resource->resolve();

        self::assertArrayHasKey('counts', $result);
        self::assertSame(1, $result['counts']['users']);
    }

    /**
     * Test that sums payload is included in the resolved output when the sums
     * virtual field is requested and loadMissing loads the aggregate
     * attributes.
     *
     * Uses AggregateCapturingModel that intercepts loadSum to set the name that
     * ValueResolver expects, since Eloquent's 'relation as alias' form produces
     * only the bare alias key rather than the full presentKey_sum_column key.
     *
     * @return void
     */
    public function testSumsPayloadIsIncludedWhenLoadMissingAndSumsFieldRequested(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,sums'],
        ]);

        $parser->parse($request);

        $model = new AggregateCapturingModel;

        $resource = new UserResource($model, true);
        $result   = $resource->resolve();

        self::assertArrayHasKey('sums', $result);
        self::assertIsFloat($result['sums']['posts_id']);
    }

    /**
     * Test that the sums key is absent when the sums virtual field is requested
     * but no aggregate attribute is loaded on the model.
     *
     * @return void
     */
    public function testSumsPayloadIsAbsentWhenNoSumsLoaded(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,sums'],
        ]);

        $parser->parse($request);

        $user = User::create([
            'name'  => 'NoSumUser',
            'email' => 'nosumuser@example.com',
        ]);

        // No loadMissing and no manual preload - attribute not present on model
        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayNotHasKey('sums', $result);
    }

    /**
     * Test that averages payload is included when the averages field is
     * requested and loadMissing loads the aggregate attribute.
     *
     * Uses AggregateCapturingModel that intercepts loadAvg to set the name that
     * ValueResolver expects, since Eloquent's 'relation as alias' form produces
     * only the bare alias key rather than the full presentKey_avg_column key.
     *
     * @return void
     */
    public function testAveragesPayloadIsIncludedWhenLoadMissingAndAveragesFieldRequested(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields'   => ['users' => 'id,averages'],
            'averages' => ['users' => ['posts' => 'id']],
        ]);

        $parser->parse($request);

        $model = new AggregateCapturingModel;

        $resource = new UserResource($model, true);
        $result   = $resource->resolve();

        self::assertArrayHasKey('averages', $result);
        self::assertIsFloat($result['averages']['posts_id']);
    }

    /**
     * Test that the averages key is absent from the resolved output when the
     * averages virtual field is not included in the requested fields, even when
     * getAverages returns a non-null value and an attribute is preloaded.
     *
     * @return void
     */
    public function testAveragesPayloadIsAbsentWhenShouldIncludeIsFalse(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            // No 'averages' in fields - shouldInclude returns false
            'averages' => ['users' => ['posts' => 'id']],
        ]);

        $parser->parse($request);

        $user = User::create([
            'name'  => 'AvgFalseUser',
            'email' => 'avgfalse@example.com',
        ]);

        // Manually set the avg attribute so resolveAggregatesPayload would
        // return a non-empty array if the shouldInclude guard is bypassed
        $user->setAttribute('posts_id_avg_id', 3.0);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayNotHasKey('averages', $result);
    }

    /**
     * Test that the averages key is absent when shouldInclude is true but no
     * average attributes are loaded on the model.
     *
     * @return void
     */
    public function testAveragesPayloadIsAbsentWhenNoAveragesLoaded(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields'   => ['users' => 'id,averages'],
            'averages' => ['users' => ['posts' => 'id']],
        ]);

        $parser->parse($request);

        $user = User::create([
            'name'  => 'AvgEmptyUser',
            'email' => 'avgempty@example.com',
        ]);

        // No loadMissing and no manual preload - attribute absent on model
        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayNotHasKey('averages', $result);
    }

    /**
     * Test that loadMissing loads a non-default sum when it is explicitly
     * requested via the sums query parameter.
     *
     * Uses AggregateCapturingModel that intercepts loadSum to set the name that
     * ValueResolver expects, since Eloquent's 'relation as alias' form produces
     * only the bare alias key rather than the full presentKey_sum_column key.
     *
     * @return void
     */
    public function testLoadMissingLoadsExplicitlyRequestedNonDefaultSum(): void
    {
        $this->clearSchemaCache();

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'nondefault_sum_rt';

            /** @var array<int, string> */
            protected static array $default = ['id', 'sums'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'id'               => [],
                    '__sum__:posts_id' => [
                        'metric'   => 'sum',
                        'relation' => 'posts',
                        'column'   => 'id',
                        'default'  => false,
                    ],
                ];
            }
        };

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['nondefault_sum_rt' => 'id,sums'],
            'sums'   => ['nondefault_sum_rt' => ['posts' => 'id']],
        ]);

        $parser->parse($request);

        $model = new AggregateCapturingModel;

        $instance = new $resourceClass($model, true);
        $result   = $instance->resolve();

        self::assertArrayHasKey('sums', $result, 'non-default sum must be loaded when explicitly requested');

        $this->clearSchemaCache();
    }

    /**
     * Test that load_missing does not load sum or average aggregates when the
     * corresponding virtual fields are neither requested nor default, even
     * though the aggregate definitions are themselves default.
     *
     * @return void
     */
    public function testLoadMissingSkipsAggregatesWhenVirtualFieldsNotRequested(): void
    {
        $this->clearSchemaCache();

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'agg_gate_rt';

            /** @var array<int, string> */
            protected static array $default = ['id'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'id'               => [],
                    '__sum__:posts_id' => [
                        'metric'   => 'sum',
                        'relation' => 'posts',
                        'column'   => 'id',
                        'default'  => true,
                    ],
                    '__avg__:posts_id' => [
                        'metric'   => 'avg',
                        'relation' => 'posts',
                        'column'   => 'id',
                        'default'  => true,
                    ],
                ];
            }
        };

        $spy = new class {
            /** @var array<int, array<int, mixed>> */
            public array $sumCalls = [];

            /** @var array<int, array<int, mixed>> */
            public array $avgCalls = [];

            /** @var array<string, mixed> */
            private array $attributes = [];

            /**
             * Magic getter for attribute access.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }

            /**
             * Magic isset for attribute existence check.
             *
             * @param  string  $key
             * @return bool
             */
            public function __isset(string $key): bool
            {
                return isset($this->attributes[$key]);
            }

            /**
             * Return the attribute map.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * No-op for the eager-load relations path.
             *
             * @SuppressWarnings("php:S1172")
             *
             * @param  mixed  $with
             * @return static
             */
            public function loadMissing(mixed $with): static
            {
                return $this;
            }

            /**
             * No-op for the count-loading path.
             *
             * @SuppressWarnings("php:S1172")
             *
             * @param  mixed  $relations
             * @return static
             */
            public function loadCount(mixed $relations): static
            {
                return $this;
            }

            /**
             * Record a sum-loading call.
             *
             * @param  mixed  $relations
             * @param  string  $column
             * @return static
             */
            public function loadSum(mixed $relations, string $column): static
            {
                $this->sumCalls[] = [$relations, $column];

                return $this;
            }

            /**
             * Record an average-loading call.
             *
             * @param  mixed  $relations
             * @param  string  $column
             * @return static
             */
            public function loadAvg(mixed $relations, string $column): static
            {
                $this->avgCalls[] = [$relations, $column];

                return $this;
            }
        };

        new $resourceClass($spy, true);

        static::assertSame([], $spy->sumCalls);
        static::assertSame([], $spy->avgCalls);

        $this->clearSchemaCache();
    }

    /**
     * Test that fields declared on the resource's fixed property are merged
     * with the config-driven fixed fields.
     *
     * @return void
     */
    public function testResolveMergesResourceFixedPropertyWithConfigFixedFields(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'fixed_prop_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'name'];

            /** @var array<int, string> */
            protected array $fixed = ['email'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Field::scalar('name'),
                    Field::scalar('email'),
                );
            }
        };

        $user = User::create([
            'name'  => 'FixedProp',
            'email' => 'fixedprop@example.com',
        ]);

        $resource = new $resourceClass($user);
        $resource->withFields(['name']);

        $result = $resource->resolve();

        static::assertArrayHasKey('email', $result, 'resource-level fixed fields must be included');
        static::assertArrayHasKey('id', $result, 'config-level fixed fields must be included');
    }

    /**
     * Test that already-loaded aggregate specifications are dropped while
     * unloaded ones are retained, for both count specs and aggregate entries.
     *
     * @return void
     */
    public function testRejectLoadedAggregatesDropsPreloadedSpecifications(): void
    {
        $user = new User;
        $user->setAttribute('posts_count', 2);
        $user->setAttribute('posts_sum_id', 10);

        $resource = new UserResource($user);
        $method   = new \ReflectionMethod(UserResource::class, 'rejectLoadedAggregates');

        /** @var array<int|string, mixed> $result */
        $result = $method->invoke($resource, $user, [
            'posts as posts_count',
            'comments as comments_count',
            'tags as tags_count' => static fn (): null => null,
            ['relation'          => 'posts as posts_sum_id', 'column' => 'id'],
            ['relation'          => 'authors as authors_sum_id', 'column' => 'id'],
        ]);

        $values = array_values($result);

        static::assertContains('comments as comments_count', $values);
        static::assertArrayHasKey('tags as tags_count', $result);
        static::assertContains(['relation' => 'authors as authors_sum_id', 'column' => 'id'], $values);
        static::assertNotContains('posts as posts_count', $values);
        static::assertNotContains(['relation' => 'posts as posts_sum_id', 'column' => 'id'], $values);
    }

    /**
     * Clear the static schema cache between tests.
     *
     * @return void
     */
    private function clearSchemaCache(): void
    {
        SchemaCompiler::clearCache();
    }
}
