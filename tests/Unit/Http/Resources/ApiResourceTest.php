<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Enums\Platform;
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

    /** @var string Field selection string that includes the aggregates field. */
    private const string AGGREGATES_FIELDS = 'id,aggregates';

    /** @var string Count metric key for the posts relation. */
    private const string COUNT_KEY_POSTS = '__aggregate__:posts_count';

    /**
     * Set up the testing environment with the fixture resources catalogued.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, PostResource::class, TagResource::class, OrganizationResource::class]);
    }

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
     * Test that getFixedFields lists the configured fixed fields, then the
     * resource's own, each once.
     *
     * @return void
     */
    public function testGetFixedFieldsListsConfiguredThenResourceFieldsOnce(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'fixed_list_test';

            /** @var array<int, string> */
            protected static array $fixed = ['id', 'status'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'), Field::scalar('status'));
            }
        };

        self::assertSame(['id', '_type'], UserResource::getFixedFields());
        self::assertSame(['id', '_type', 'status'], $resourceClass::getFixedFields());
    }

    /**
     * Test that getAllFields returns all field keys excluding aggregates.
     *
     * @return void
     */
    public function testGetAllFieldsReturnsAllFieldKeysExcludingAggregates(): void
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
            self::assertStringNotContainsString('__aggregate__', $field);
        }
    }

    /**
     * Test that resolveFields returns API query fields when set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     * Test that a computed field receives the request the resource is resolved
     * with, and no request when resolved with anything other than a request.
     *
     * @return void
     */
    public function testResolvePassesOnlyARequestToComputedFields(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'request_aware_test';

            /** @var array<int, string> */
            protected static array $default = ['path'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::compute('path', static fn (ApiResource $resource, ?Request $request): ?string => $request?->path()),
                );
            }
        };

        $user = User::create([
            'name'  => 'Requested',
            'email' => 'requested@example.com',
        ]);

        $resource = new $resourceClass($user);

        self::assertSame('users/requested', $resource->resolve(Request::create('/users/requested'))['path']);
        self::assertNull($resource->resolve('users/requested')['path']);
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
                    Field::scalar('name')->transform(fn ($resource, $value) => is_string($value) ? strtoupper($value) : $value),
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
     * Test that a field cast to a pure enum serialises as the lowercase case
     * name, the form the API accepts it in.
     *
     * @return void
     */
    public function testPureEnumCastFieldSerialisesAsLowercaseName(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'pure_enum_test';

            /** @var array<int, string> */
            protected static array $default = ['platform'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('platform'));
            }
        };

        $model = new class extends Model {
            /**
             * Get the attributes that should be cast.
             *
             * @return array<string, string>
             */
            #[\Override]
            protected function casts(): array
            {
                return ['platform' => Platform::class];
            }
        };

        $model->forceFill(['platform' => Platform::MOBILE_APP]);

        $resource = new $resourceClass($model);
        $resource->withFields(['platform']);

        $result = $resource->resolve();

        self::assertSame('mobile_app', $result['platform']);
        self::assertStringContainsString('"platform":"mobile_app"', json_encode($result, JSON_THROW_ON_ERROR));
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
     * Test that the aggregates payload is included when requested.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAggregatesPayloadIsIncludedWhenRequested(): void
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

        $user->loadCount('posts as aggregate_posts_count');

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,aggregates'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertSame(['posts_count' => 2], $result['aggregates']);
    }

    /**
     * Test that a default count is included when the aggregates field is
     * requested.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testCountsIncludedWhenDefaultFlagIsSet(): void
    {
        $user = User::create([
            'name'  => 'DefaultCount',
            'email' => 'defcount@example.com',
        ]);

        $user->loadCount('posts as aggregate_posts_count');

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'id,name,aggregates'],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertSame(['posts_count' => 0], $result['aggregates']);
    }

    /**
     * Test that eagerLoadMapFor builds correct eager-load map.
     *
     * @return void
     */
    public function testEagerLoadMapForBuildsCorrectMap(): void
    {
        Config::set('api-toolkit.resources.relation_limit', 0);

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
                    Relation::to('items', TagResource::class)->constrain(static function (BuilderContract $query): void {
                        $query->where('active', true);
                    }),
                );
            }
        };

        $fields = ['items'];

        $map = $resourceClass::eagerLoadMapFor($fields);

        self::assertArrayHasKey('items', $map);
        self::assertIsCallable($map['items']);
    }

    /**
     * Test that eagerLoadAggregatesFor builds the requested entries, else the
     * defaults.
     *
     * @return void
     */
    public function testEagerLoadAggregatesForBuildsTheAggregateMap(): void
    {
        self::assertSame(
            [['relation' => 'posts as aggregate_posts_count', 'column' => '*', 'function' => 'count']],
            UserResource::eagerLoadAggregatesFor(['posts_count']),
        );
        self::assertCount(2, UserResource::eagerLoadAggregatesFor(null));
        self::assertSame([], OrganizationResource::eagerLoadAggregatesFor(null));
        self::assertSame('users as aggregate_users_count', OrganizationResource::eagerLoadAggregatesFor(['users_count'])[0]['relation']);
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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

        Config::set('api-toolkit.resources.relation_limit', 0);

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
                        ->transform(fn ($resource, $value) => is_string($value) ? strtoupper($value) : $value)
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
     * Test eagerLoadAggregatesFor with a constrained count returns its closure.
     *
     * @return void
     */
    public function testEagerLoadAggregatesForWithConstrainedCountReturnsClosure(): void
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
                    Count::of('items')->constrain(static function (Builder $query): void {
                        $query->where('active', true);
                    })->default(),
                );
            }
        };

        $aggregates = $resourceClass::eagerLoadAggregatesFor(null);
        $relation   = $aggregates[0]['relation'];

        self::assertIsArray($relation);
        self::assertArrayHasKey('items as aggregate_items_count', $relation);
        self::assertIsCallable($relation['items as aggregate_items_count']);
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     * Test that load_missing over a model without related rows renders the
     * count as zero and omits the null sum.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConstructorWithLoadMissingRendersAZeroCountAndOmitsANullSum(): void
    {
        $user = User::create([
            'name'  => 'CountLoad',
            'email' => 'countload@example.com',
        ]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => self::AGGREGATES_FIELDS],
        ]);
        $parser->parse($request);

        $result = (new UserResource($user, true))->resolve();

        self::assertSame(['posts_count' => 0], $result['aggregates']);
        self::assertArrayHasKey('aggregate_posts_sum_id', $user->getAttributes());
    }

    /**
     * Test that resolveAggregatesPayload returns an empty array for a
     * non-object resource nested inside a JsonResource.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testResolveAggregatesPayloadReturnsEmptyForNullResource(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), ['fields' => ['users' => self::AGGREGATES_FIELDS]]);
        $parser->parse($request);

        $inner    = new UserResource(null);
        $resource = new UserResource($inner);

        $result = $resource->resolve();

        self::assertArrayNotHasKey('aggregates', $result);
    }

    /**
     * Test that a count excluded by a guard is omitted from the aggregates
     * payload.
     *
     * @SuppressWarnings("php:S2014")
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testCountExcludedByGuardIsOmittedFromPayload(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'guarded_count_test';

            /** @var array<int, string> */
            protected static array $default = ['id', 'aggregates'];

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

        $user->loadCount('posts as aggregate_posts_count');

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), ['fields' => ['guarded_count_test' => self::AGGREGATES_FIELDS]]);

        Config::set(ResourceDiscovery::RESOURCES_KEY, [$resourceClass::class]);

        $parser->parse($request);

        $resource = new $resourceClass($user);
        $result   = $resource->resolve();

        self::assertArrayNotHasKey('aggregates', $result);
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
     * field projections.
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
     * reach the underlying model.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUnwrapResourceLoopsThroughNestedJsonResource(): void
    {
        $user = User::create([
            'name'  => 'Wrapped',
            'email' => 'wrapped2@example.com',
        ]);

        $user->loadCount('posts as aggregate_posts_count');

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), ['fields' => ['users' => self::AGGREGATES_FIELDS]]);
        $parser->parse($request);

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
     * Test that eagerLoadMapFor skips fields not in the schema.
     *
     * @return void
     */
    public function testEagerLoadMapForSkipsFieldsNotInSchema(): void
    {
        $map = UserResource::eagerLoadMapFor(['nonexistent_field_xyz']);

        self::assertSame([], $map);
    }

    /**
     * Test that eagerLoadMapFor skips metric fields when walking relations.
     *
     * @return void
     */
    public function testEagerLoadMapForSkipsMetricFields(): void
    {
        $map = UserResource::eagerLoadMapFor([self::COUNT_KEY_POSTS]);

        self::assertSame([], $map);
    }

    /**
     * Test that eagerLoadMapFor includes extras paths defined on a relation.
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
     * relation is requested twice.
     *
     * @return void
     */
    public function testEagerLoadMapForDoesNotAddDuplicatePaths(): void
    {
        Config::set('api-toolkit.resources.relation_limit', 0);

        $map = UserResource::eagerLoadMapFor(['organization', 'organization']);

        $plainValues = array_values($map);

        self::assertContains('organization', $plainValues);
        self::assertCount(1, array_keys($map, 'organization', true));
    }

    /**
     * Test that resolveChildFields falls back to getAllFields when the child
     * resource has empty defaults and no requested fields.
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

        Config::set('api-toolkit.resources.relation_limit', 0);

        $outerClass::$childClassName = $childClassName;

        $map = $outerClass::eagerLoadMapFor(['rel']);

        self::assertContains('rel', $map);
    }

    /**
     * Test that resolveSimpleProperty reflects on a model method that does not
     * return an Attribute.
     *
     * @return void
     */
    public function testResolveSimplePropertyReflectsOnModelMethod(): void
    {
        $this->clearSchemaCache();

        // 'posts' is a scalar, so the HasMany method User::posts() is reflected
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

        self::assertArrayHasKey('_type', $result);
    }

    /**
     * Test that the scoped-constraint closure returned by eagerLoadMapFor runs
     * the constraint on the relation it is given.
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
                        ->constrain(static function (BuilderContract $query): void {
                            $query->where('name', 'Active');
                        }),
                );
            }
        };

        $map = $resourceClass::eagerLoadMapFor(['organization']);

        self::assertArrayHasKey('organization', $map);
        self::assertIsCallable($map['organization']);

        $relation = EloquentRelation::noConstraints(static fn (): BelongsTo => (new User)->organization());
        ($map['organization'])($relation);

        self::assertSame(['name'], array_column($relation->getQuery()->getQuery()->wheres, 'column'));
    }

    /**
     * Test that resolveSimpleProperty returns the accessor value when the model
     * method's return type is Attribute.
     *
     * @return void
     */
    public function testResolveSimplePropertyReturnsValueWhenMethodReturnsAttributeType(): void
    {
        $this->clearSchemaCache();

        $model = new #[Table('users')] class extends Model {
            /**
             * Get the label attribute.
             *
             * @return \Illuminate\Database\Eloquent\Casts\Attribute
             */
            public function label(): Attribute
            {
                return Attribute::get(
                    static fn (): string => 'attr_value',
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
     * Test that an owner exposing magic attributes but no attribute listing
     * yields no aggregates, so an accessor is never read in place of one.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAggregatesAreNotReadFromMagicAttributes(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), ['fields' => ['users' => self::AGGREGATES_FIELDS]]);
        $parser->parse($request);

        // Exposes __isset/__get but no getAttributes()
        $fakeOwner = new class {
            /**
             * @param  string  $name
             * @return bool
             */
            public function __isset(string $name): bool
            {
                return $name === 'aggregate_posts_count';
            }

            /**
             * @param  string  $name
             * @return mixed
             */
            public function __get(string $name): mixed
            {
                return $name === 'aggregate_posts_count' ? 2 : null;
            }
        };

        $resource = new UserResource($fakeOwner);
        $result   = $resource->resolve();

        self::assertArrayNotHasKey('aggregates', $result);
    }

    /**
     * Test that the scoped-constraint closure passes a MorphTo relation
     * directly to the user constraint.
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

            /** @var \Illuminate\Contracts\Database\Eloquent\Builder|null The query the constraint last received */
            public static ?BuilderContract $received = null;

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
                        ->constrain(static function (BuilderContract $query): void {
                            self::$received = $query;
                        }),
                );
            }
        };

        $map = $resourceClass::eagerLoadMapFor(['organization']);

        self::assertArrayHasKey('organization', $map);
        self::assertIsCallable($map['organization']);

        $model = new #[Table('comments')] class extends Model {
            /**
             * Get the commented subject.
             *
             * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
             */
            public function subject(): MorphTo
            {
                return $this->morphTo();
            }
        };

        $morphTo = EloquentRelation::noConstraints(static fn (): MorphTo => $model->subject());
        ($map['organization'])($morphTo);

        self::assertSame($morphTo, $resourceClass::$received);
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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

        // The defaults hold no relations, so only all-fields mode loads any
        new UserResource($user, true, ':all');

        self::assertTrue($user->relationLoaded('organization'));
    }

    /**
     * Test that load_missing does not load aggregates when the aggregates field
     * is not requested.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConstructorWithLoadMissingSkipsAggregatesWhenNotRequested(): void
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

        self::assertArrayNotHasKey('aggregate_posts_count', $user->getAttributes());
    }

    /**
     * Test that load_missing loads the default aggregates and resolves them
     * into the aggregates payload when the aggregates field is requested.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConstructorWithLoadMissingLoadsDefaultAggregates(): void
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
            'fields' => ['users' => self::AGGREGATES_FIELDS],
        ]);

        $parser->parse($request);

        $resource = new UserResource($user, true);
        $result   = $resource->resolve();

        $expectedSum = (float) Post::query()->where('user_id', $user->id)->sum('id'); // @phpstan-ignore cast.double

        self::assertSame(['posts_count' => 2, 'posts_sum_id' => $expectedSum], $result['aggregates']);
    }

    /**
     * Test that load_missing honours explicitly requested aggregate keys from
     * the API query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConstructorWithLoadMissingHonoursRequestedAggregateKeys(): void
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
            'fields'     => ['organizations' => self::AGGREGATES_FIELDS],
            'aggregates' => ['organizations' => 'users_count'],
        ]);

        $parser->parse($request);

        $resource = new OrganizationResource($org, true);
        $result   = $resource->resolve();

        self::assertSame(['users_count' => 1], $result['aggregates']);
    }

    /**
     * Test that load_missing loads each default aggregate once, under its
     * attribute, and the payload reads it back.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testLoadMissingLoadsEachDefaultAggregateUnderItsAttribute(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => self::AGGREGATES_FIELDS],
        ]);

        $parser->parse($request);

        $model = new AggregateCapturingModel;

        $resource = new UserResource($model, true);
        $result   = $resource->resolve();

        self::assertSame([
            [['posts as aggregate_posts_count'], '*', 'count'],
            [['posts as aggregate_posts_sum_id'], 'id', 'sum'],
        ], $model->calls);
        self::assertSame(['posts_count' => 2, 'posts_sum_id' => 42.0], $result['aggregates']);
    }

    /**
     * Test that the aggregates key is absent when the aggregates field is
     * requested but no aggregate attribute is loaded on the model.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAggregatesPayloadIsAbsentWhenNoneLoaded(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields' => ['users' => self::AGGREGATES_FIELDS],
        ]);

        $parser->parse($request);

        $user = User::create([
            'name'  => 'NoSumUser',
            'email' => 'nosumuser@example.com',
        ]);

        // No loadMissing and no manual preload - attribute not present on model
        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayNotHasKey('aggregates', $result);
    }

    /**
     * Test that a requested aggregate replaces every default, so only it is
     * loaded and rendered.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRequestedAggregateReplacesTheDefaults(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields'     => ['users' => self::AGGREGATES_FIELDS],
            'aggregates' => ['users' => 'posts_avg_id'],
        ]);

        $parser->parse($request);

        $model = new AggregateCapturingModel;

        $resource = new UserResource($model, true);
        $result   = $resource->resolve();

        self::assertSame([[['posts as aggregate_posts_avg_id'], 'id', 'avg']], $model->calls);
        self::assertSame(['posts_avg_id' => 3.5], $result['aggregates']);
    }

    /**
     * Test that the aggregates key is absent when the aggregates field is not
     * requested, even with an aggregate attribute preloaded.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAggregatesPayloadIsAbsentWhenTheFieldIsNotRequested(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'aggregates' => ['users' => 'posts_avg_id'],
        ]);

        $parser->parse($request);

        $user = User::create([
            'name'  => 'AvgFalseUser',
            'email' => 'avgfalse@example.com',
        ]);

        // Preloaded so only the field token can omit the payload
        $user->setAttribute('aggregate_posts_avg_id', 3.0);

        $resource = new UserResource($user);
        $result   = $resource->resolve();

        self::assertArrayNotHasKey('aggregates', $result);
    }

    /**
     * Test that loadMissing loads a non-default sum explicitly requested via
     * the aggregates query parameter.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testLoadMissingLoadsExplicitlyRequestedNonDefaultSum(): void
    {
        $this->clearSchemaCache();

        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'nondefault_sum_rt';

            /** @var array<int, string> */
            protected static array $default = ['id', 'aggregates'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'id'                         => [],
                    '__aggregate__:posts_sum_id' => [
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
            'fields'     => ['nondefault_sum_rt' => self::AGGREGATES_FIELDS],
            'aggregates' => ['nondefault_sum_rt' => 'posts_sum_id'],
        ]);

        Config::set(ResourceDiscovery::RESOURCES_KEY, [$resourceClass::class]);

        $parser->parse($request);

        $model = new AggregateCapturingModel;

        $instance = new $resourceClass($model, true);
        $result   = $instance->resolve();

        self::assertSame(['posts_sum_id' => 42.0], $result['aggregates'], 'non-default sum must be loaded when explicitly requested');

        $this->clearSchemaCache();
    }

    /**
     * Test that load_missing skips every aggregate while the aggregates field
     * is neither requested nor default, even when the definitions are default.
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
                    'id'                         => [],
                    '__aggregate__:posts_sum_id' => [
                        'metric'   => 'sum',
                        'relation' => 'posts',
                        'column'   => 'id',
                        'default'  => true,
                    ],
                    '__aggregate__:posts_avg_id' => [
                        'metric'   => 'avg',
                        'relation' => 'posts',
                        'column'   => 'id',
                        'default'  => true,
                    ],
                ];
            }
        };

        $spy = new AggregateCapturingModel;

        new $resourceClass($spy, true);

        self::assertSame([], $spy->calls);

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
            protected static array $fixed = ['email'];

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

        self::assertArrayHasKey('email', $result, 'resource-level fixed fields must be included');
        self::assertArrayHasKey('id', $result, 'config-level fixed fields must be included');
    }

    /**
     * Test that load_missing skips an aggregate the model already carries, even
     * one loaded as null, while loading the rest.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testLoadMissingSkipsAnAggregateAlreadyLoaded(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser  = $this->app->make('api.query');
        $request = Request::create('/', HttpMethod::GET->getVerb(), [
            'fields'     => ['users' => self::AGGREGATES_FIELDS],
            'aggregates' => ['users' => 'posts_count,posts_sum_id,posts_avg_id'],
        ]);

        $parser->parse($request);

        $model = new AggregateCapturingModel(['aggregate_posts_count' => 5, 'aggregate_posts_avg_id' => null]);

        $result = (new UserResource($model, true))->resolve();

        self::assertSame([[['posts as aggregate_posts_sum_id'], 'id', 'sum']], $model->calls);
        self::assertSame(['posts_count' => 5, 'posts_sum_id' => 42.0], $result['aggregates']);
    }

    /**
     * Test that load_missing loads the aggregates sharing a function and a
     * column in one call, constrained or not, and no others together.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testLoadMissingLoadsAggregatesSharingAFunctionInOneCall(): void
    {
        $this->clearSchemaCache();

        $published = static function (Builder $query): void {
            $query->where('published', true);
        };

        $resourceClass = $this->groupedCountsResource($published);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse(Request::create('/', HttpMethod::GET->getVerb()));

        $model  = new AggregateCapturingModel;
        $result = (new $resourceClass($model, true))->resolve();

        self::assertSame([
            [['posts as aggregate_posts_count', 'posts as aggregate_published_posts' => $published], '*', 'count'],
            [['posts as aggregate_posts_exists'], '*', 'exists'],
            [['posts as aggregate_posts_sum_id'], 'id', 'sum'],
            [['posts as aggregate_posts_sum_user_id'], 'user_id', 'sum'],
        ], $model->calls);
        self::assertSame(
            ['posts_count' => 2, 'published_posts' => 2, 'posts_exists' => true, 'posts_sum_id' => 42.0, 'posts_sum_user_id' => 42.0],
            $result['aggregates'],
        );

        $this->clearSchemaCache();
    }

    /**
     * Test that a grouped load reads each aggregate of a real model correctly
     * in one query per function and column.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testLoadMissingGroupedCountsReadEachConstraintFromTheDatabase(): void
    {
        $this->clearSchemaCache();

        $resourceClass = $this->groupedCountsResource(static function (Builder $query): void {
            $query->where('published', true);
        });

        $user = User::create(['name' => 'Grouped', 'email' => 'grouped@example.com']);

        Post::create(['user_id' => $user->id, 'title' => 'One', 'body' => 'Body', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'Two', 'body' => 'Body', 'published' => false]);

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse(Request::create('/', HttpMethod::GET->getVerb()));

        $connection = $user->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $resource = new $resourceClass($user, true);
        $queries  = count($connection->getQueryLog());

        $connection->disableQueryLog();

        self::assertSame(4, $queries);
        self::assertSame([
            'posts_count'       => 2,
            'published_posts'   => 1,
            'posts_exists'      => true,
            'posts_sum_id'      => (float) $user->posts()->sum('id'), // @phpstan-ignore cast.double
            'posts_sum_user_id' => (float) $user->posts()->sum('user_id'), // @phpstan-ignore cast.double
        ], $resource->resolve()['aggregates']);

        $this->clearSchemaCache();
    }

    /**
     * Test that a relation cut to its limit is listed under its response key,
     * even when the application loaded it unbounded.
     *
     * @return void
     */
    public function testCutRelationIsListedUnderItsResponseKey(): void
    {
        $result = (new (self::truncatingResource()::class)($this->userWithPosts(3)))->withFields(['id', 'writings', 'tags'])->resolve();

        self::assertSame(['writings'], $result['_truncated']);
        self::assertIsObject($result['writings']);
        self::assertCount(2, $result['writings']->collection); // @phpstan-ignore property.notFound
    }

    /**
     * Test that every cut relation is listed, in the order it resolved.
     *
     * @return void
     */
    public function testEveryCutRelationIsListedInResolutionOrder(): void
    {
        $user = $this->userWithPosts(3);

        $user->setRelation('posts_copy', $user->getRelation('posts'));

        $result = (new (self::truncatingResource()::class)($user))->withFields(['id', 'writings', 'copies'])->resolve();

        self::assertSame(['writings', 'copies'], $result['_truncated']);
    }

    /**
     * Test that no truncation list is added when nothing was cut.
     *
     * @return void
     */
    public function testNoTruncationListWhenNothingIsCut(): void
    {
        $result = (new (self::truncatingResource()::class)($this->userWithPosts(2)))->withFields(['id', 'writings'])->resolve();

        self::assertArrayNotHasKey('_truncated', $result);
        self::assertArrayHasKey('writings', $result);
    }

    /**
     * Test that a cut relation a transformer drops from the payload is not
     * listed.
     *
     * @return void
     */
    public function testCutRelationDroppedFromThePayloadIsNotListed(): void
    {
        $result = (new (self::truncatingResource()::class)($this->userWithPosts(3)))->withFields(['id', 'hidden_writings', 'name'])->resolve();

        self::assertArrayNotHasKey('hidden_writings', $result);
        self::assertArrayNotHasKey('_truncated', $result);
    }

    /**
     * Build a resource declaring two counts over one relation, one of them
     * constrained, an existence check and sums over two columns.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void  $constraint
     * @return class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>
     */
    private function groupedCountsResource(\Closure $constraint): string
    {
        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'grouped_counts_rt';

            /** @var (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null The constraint the published count applies */
            public static ?\Closure $constraint = null;

            /** @var array<int, string> */
            protected static array $default = ['id', 'aggregates'];

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'id'                              => [],
                    '__aggregate__:posts_count'       => ['metric' => 'count', 'relation' => 'posts', 'default' => true],
                    '__aggregate__:published_posts'   => ['metric' => 'count', 'relation' => 'posts', 'constraint' => self::$constraint, 'default' => true],
                    '__aggregate__:posts_exists'      => ['metric' => 'exists', 'relation' => 'posts', 'default' => true],
                    '__aggregate__:posts_sum_id'      => ['metric' => 'sum', 'relation' => 'posts', 'column' => 'id', 'default' => true],
                    '__aggregate__:posts_sum_user_id' => ['metric' => 'sum', 'relation' => 'posts', 'column' => 'user_id', 'default' => true],
                ];
            }
        };

        $resource::$constraint = $constraint;

        return $resource::class;
    }

    /**
     * Build a resource whose posts relations are limited to two.
     *
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResource
     */
    private static function truncatingResource(): ApiResource
    {
        return new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'truncating_users';

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
                    Relation::to('posts', PostResource::class, 'writings')->limit(2),
                    Relation::to('posts_copy', PostResource::class, 'copies')->limit(2),
                    Relation::to('posts', PostResource::class, 'hidden_writings')->limit(2)->transform(static fn (): MissingValue => new MissingValue),
                );
            }
        };
    }

    /**
     * Create a user with the given number of posts loaded unbounded.
     *
     * @param  int  $count
     * @return \Tests\Fixtures\Models\User
     */
    private function userWithPosts(int $count): User
    {
        $user = User::create(['name' => 'Truncated', 'email' => 'truncated@example.com']);

        for ($index = 1; $index <= $count; $index++) {
            Post::create(['user_id' => $user->id, 'title' => 'Post ' . $index, 'body' => 'Body']);
        }

        return $user->load('posts');
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
