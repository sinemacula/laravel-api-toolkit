<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ApiResourceIntegrationTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    public function testResourceResolvesSimpleFieldsRelationsComputedValuesAndCounts(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme']);

        $user = User::query()->create([
            'name'            => 'Alice',
            'organization_id' => $organization->id,
        ]);

        $posts = collect([
            Post::query()->create(['user_id' => $user->id, 'title' => 'A', 'published' => true]),
            Post::query()->create(['user_id' => $user->id, 'title' => 'B', 'published' => false]),
        ]);

        $user->setRelation('organization', $organization);
        $user->setRelation('posts', $posts);
        $user->setAttribute('posts_count', 2);

        ApiQuery::parse(Request::create('/api/users', 'GET', [
            'fields' => ['user' => 'id,name_upper,nickname,computed_method,computed_callback,organization,organization_name,posts,counts'],
            'counts' => ['user' => 'posts'],
            'suffix' => 'ok',
        ]));

        $resource = new UserResource($user);
        $array    = $resource->resolve(Request::create('/api/users', 'GET', ['suffix' => 'ok']));

        static::assertSame('user', $array['_type']);
        static::assertSame('ALICE', $array['name_upper']);
        static::assertSame('ALICE', $array['nickname']);
        static::assertSame('computed:ok', $array['computed_method']);
        static::assertSame('callback:' . $user->id, $array['computed_callback']);
        static::assertSame('Acme', $array['organization_name']);
        static::assertArrayHasKey('organization', $array);
        static::assertArrayHasKey('posts', $array);
        static::assertSame(['posts' => 2], $array['counts']);
        static::assertArrayNotHasKey('secret', $array);
    }

    public function testResourceConstructorCanLoadMissingRelationsAndCounts(): void
    {
        ApiQuery::parse(Request::create('/api/users', 'GET', [
            'fields' => ['user' => 'organization,counts'],
            'counts' => ['user' => 'posts'],
        ]));

        $owner = new class {
            public array $loaded  = [];
            public array $counted = [];

            public function loadMissing(array $with): void
            {
                $this->loaded = $with;
            }

            public function loadCount(array $with): void
            {
                $this->counted = $with;
            }
        };

        new UserResource($owner, true);

        static::assertNotEmpty($owner->loaded);
        static::assertNotEmpty($owner->counted);
    }

    public function testEagerLoadHelpersReturnExpectedMaps(): void
    {
        ApiQuery::parse(Request::create('/api/users', 'GET', [
            'fields' => ['organization' => 'id,name'],
            'counts' => ['user' => 'posts,published_posts'],
        ]));

        $map = UserResource::eagerLoadMapFor(['organization', 'posts']);

        static::assertContains('organization', $map);
        static::assertArrayHasKey('posts', $map);

        $counts = UserResource::eagerLoadCountsFor(['posts', 'published_posts']);

        static::assertContains('posts', $counts);
        static::assertArrayHasKey('posts', $counts);
    }

    public function testProtectedFieldResolutionHelpersCoverSimpleComputedAccessorAndRelationBranches(): void
    {
        $resource = new UserResource((object) ['id' => 1, 'name' => 'Alice']);

        $simple = $this->invokeNonPublic($resource, 'resolveSimpleProperty', 'name');
        static::assertSame('Alice', $simple);

        $missing = $this->invokeNonPublic(new UserResource('not-object'), 'resolveSimpleProperty', 'name');
        static::assertInstanceOf(MissingValue::class, $missing);

        $computed = $this->invokeNonPublic($resource, 'resolveComputedValue', 'resolveComputedMethod', Request::create('/x'));
        static::assertStringContainsString('computed', $computed);

        $callable = $this->invokeNonPublic($resource, 'resolveComputedValue', static fn () => 'ok', Request::create('/x'));
        static::assertSame('ok', $callable);

        $invalidComputed = $this->invokeNonPublic($resource, 'resolveComputedValue', 'missingMethod', Request::create('/x'));
        static::assertInstanceOf(MissingValue::class, $invalidComputed);

        $accessorValue = $this->invokeNonPublic($resource, 'resolveAccessorValue', 'name', Request::create('/x'));
        static::assertSame('Alice', $accessorValue);

        $accessorCallback = $this->invokeNonPublic($resource, 'resolveAccessorValue', static fn () => 'value', Request::create('/x'));
        static::assertSame('value', $accessorCallback);

        $invalidAccessor = $this->invokeNonPublic($resource, 'resolveAccessorValue', 123, Request::create('/x'));
        static::assertInstanceOf(MissingValue::class, $invalidAccessor);

        $user = User::query()->create(['name' => 'Bob']);
        $user->setRelation('organization', Organization::query()->create(['name' => 'Org']));

        $relationValue = $this->invokeNonPublic(new UserResource($user), 'resolveRelationValue', [
            'relation' => ['organization'],
            'resource' => OrganizationResource::class,
            'fields'   => ['id', 'name'],
        ], Request::create('/x'));

        static::assertInstanceOf(ApiResource::class, $relationValue);

        $relationAccessor = $this->invokeNonPublic(new UserResource($user), 'resolveRelationValue', [
            'relation' => ['organization'],
            'accessor' => 'name',
        ], Request::create('/x'));

        static::assertSame('Org', $relationAccessor);

        $missingRelation = $this->invokeNonPublic(new UserResource($user), 'resolveRelationValue', [
            'relation' => ['posts'],
            'resource' => PostResource::class,
        ], Request::create('/x'));

        static::assertInstanceOf(MissingValue::class, $missingRelation);
    }

    public function testPrivateHelpersHandleTransformersWrappersFixedFieldsAndCountsInclusionRules(): void
    {
        $user = User::query()->create(['name' => 'Alice']);

        ApiQuery::parse(Request::create('/api/users', 'GET', [
            'fields' => ['user' => ':all'],
        ]));

        $resource = (new UserResource($user))->withAll();

        static::assertTrue($this->invokeNonPublic($resource, 'shouldRespondWithAll'));
        static::assertContains('id', $this->invokeNonPublic($resource, 'getFixedFields'));

        $transformed = $this->invokeNonPublic($resource, 'applyTransformers', [
            static fn (ApiResource $resource, mixed $value) => $value . '-one',
            static fn (ApiResource $resource, mixed $value) => $value . '-two',
            'not-callable',
        ], 'base');

        static::assertSame('base-one-two', $transformed);

        $wrapped = $this->invokeNonPublic($resource, 'wrapRelatedWithResource', collect([$user]), UserResource::class, ['id']);
        static::assertInstanceOf(ApiResourceCollection::class, $wrapped);

        $wrappedSingle = $this->invokeNonPublic($resource, 'wrapRelatedWithResource', $user, UserResource::class, ['id']);
        static::assertInstanceOf(UserResource::class, $wrappedSingle);

        static::assertSame($user, $this->invokeNonPublic($resource, 'unwrapResource', new JsonResource($user)));

        static::assertSame('organization', $this->invokeNonPublic($resource, 'getPrimaryRelationName', ['relation' => ['organization']]));
        static::assertNull($this->invokeNonPublic($resource, 'getPrimaryRelationName', ['relation' => ['']]));

        static::assertNull($this->invokeNonPublic($resource, 'getRelationResourceClass', []));
        static::assertSame(UserResource::class, $this->invokeNonPublic($resource, 'getRelationResourceClass', ['resource' => UserResource::class]));

        static::assertNull($this->invokeNonPublic($resource, 'getRelationFields', ['fields' => 'invalid']));
        static::assertSame([], $this->invokeNonPublic($resource, 'getRelationFields', ['fields' => ['']]));

        $owner = new class {
            public function __isset(string $name): bool
            {
                return $name === 'posts_count';
            }

            public function __get(string $name): int
            {
                return 4;
            }
        };

        static::assertSame(4, $this->invokeNonPublic($resource, 'getAttributeIfLoaded', $owner, 'posts_count'));
        static::assertNull($this->invokeNonPublic($resource, 'getAttributeIfLoaded', $owner, 'missing'));

        static::assertTrue($this->invokeNonPublic(UserResource::class, 'shouldIncludeCount', 'posts', ['posts'], ['default' => false]));
        static::assertFalse($this->invokeNonPublic(UserResource::class, 'shouldIncludeCount', 'posts', [], ['default' => false]));

        static::assertTrue($this->invokeNonPublic(UserResource::class, 'shouldRecurseIntoChild', ['resource' => UserResource::class]));
        static::assertFalse($this->invokeNonPublic(UserResource::class, 'shouldRecurseIntoChild', ['resource' => '']));

        $visited = [];
        static::assertFalse($this->invokeNonPublic(UserResource::class, 'wasVisited', $visited, UserResource::class, 'organization'));

        $markVisited = new \ReflectionMethod(UserResource::class, 'markVisited');
        $markVisited->setAccessible(true);
        $markVisited->invokeArgs(null, [&$visited, UserResource::class, 'organization']);

        static::assertTrue($this->invokeNonPublic(UserResource::class, 'wasVisited', $visited, UserResource::class, 'organization'));

        static::assertSame('organization.name', $this->invokeNonPublic(UserResource::class, 'makePrefixedPath', 'organization', 'name'));

        $paths = [];

        $addPath = new \ReflectionMethod(UserResource::class, 'addPath');
        $addPath->setAccessible(true);
        $addPath->invokeArgs(null, [&$paths, 'organization']);

        $addExtras = new \ReflectionMethod(UserResource::class, 'addExtras');
        $addExtras->setAccessible(true);
        $addExtras->invokeArgs(null, [&$paths, 'organization', ['team', 'group']]);

        static::assertSame(['organization', 'organization.team', 'organization.group'], $paths);

        static::assertNull($this->invokeNonPublic(UserResource::class, 'findDefinition', [], 'id'));
        static::assertSame(['relation' => 'organization'], $this->invokeNonPublic(UserResource::class, 'findDefinition', ['id' => ['relation' => 'organization']], 'id'));

        static::assertSame(['organization'], $this->invokeNonPublic(UserResource::class, 'extractRelations', ['relation' => ['organization']]));
        static::assertSame(['organization.team'], $this->invokeNonPublic(UserResource::class, 'extractExtraPaths', ['extras' => ['organization.team', '']]));

        $constraint = static fn () => null;
        static::assertSame($constraint, $this->invokeNonPublic(UserResource::class, 'extractConstraint', ['constraint' => $constraint]));
        static::assertNull($this->invokeNonPublic(UserResource::class, 'extractConstraint', ['constraint' => 'not-closure']));
    }

    public function testResourceCollectionHandlesApiResourcesPaginationHeadersAndCursorMetadata(): void
    {
        $items = new Collection([
            new UserResource((object) ['id' => 1, 'name' => 'A']),
            new UserResource((object) ['id' => 2, 'name' => 'B']),
        ]);

        $collection = new ApiResourceCollection($items, UserResource::class);

        $array = $collection->withFields(['id'])->withoutFields(['name'])->toArray(Request::create('/api/users'));

        static::assertCount(2, $array);
        static::assertArrayHasKey('id', $array[0]);
        static::assertArrayNotHasKey('name', $array[0]);

        $paginator = new LengthAwarePaginator([
            ['id' => 1],
            ['id' => 2],
        ], 20, 2, 1, ['path' => '/api/users']);

        $paginatedCollection = new ApiResourceCollection($paginator, UserResource::class);

        $response = response()->json();
        $paginatedCollection->withResponse(Request::create('/api/users'), $response);

        static::assertSame('20', $response->headers->get('Total-Count'));

        $info = $paginatedCollection->paginationInformation(Request::create('/api/users'), [], []);

        static::assertSame(20, $info['meta']['total']);
        static::assertArrayHasKey('next', $info['links']);

        request()->server->set('REQUEST_URI', '/api/users?cursor=abc');

        $cursorPaginator = new CursorPaginator([
            ['id' => 1],
        ], 1, new Cursor(['id' => 1], true), [
            'path'       => '/api/users',
            'cursorName' => 'cursor',
        ]);

        $cursorCollection = new ApiResourceCollection($cursorPaginator, UserResource::class);
        $cursorInfo       = $cursorCollection->paginationInformation(Request::create('/api/users'), [], []);

        static::assertArrayHasKey('continue', $cursorInfo['meta']);
        static::assertArrayHasKey('self', $cursorInfo['links']);

        $plainCollection = new ApiResourceCollection(new Collection([]), UserResource::class);
        static::assertSame([], $plainCollection->paginationInformation(Request::create('/api/users'), [], []));

        $rawItemsCollection = new ApiResourceCollection(new Collection([
            (object) ['id' => 10, 'name' => 'Raw'],
        ]), UserResource::class);

        $rawArray = $rawItemsCollection->toArray(Request::create('/api/users'));
        static::assertSame('Raw', $rawArray[0]['name']);

        $nonApiCollection = new ApiResourceCollection(new Collection([
            new NonApiResolvableResource('original'),
        ]), NonApiResolvableResource::class);

        $nonApiArray = $nonApiCollection->toArray(Request::create('/api/users'));
        static::assertSame('wrapped:original', $nonApiArray[0]['name']);
    }

    public function testResourceTypeValidationAndDefinitionDiscoveryHelpers(): void
    {
        $this->expectException(\LogicException::class);

        MissingResourceTypeResource::getResourceType();
    }

    public function testResolveChildFieldsUsesExplicitRequestedDefaultAndAllFallbacks(): void
    {
        ApiQuery::parse(Request::create('/api/users', 'GET', [
            'fields' => ['organization' => 'name'],
        ]));

        $explicit = $this->invokeNonPublic(UserResource::class, 'resolveChildFields', ['fields' => ['id', 'name']], OrganizationResource::class);
        static::assertSame(['id', 'name'], $explicit);

        $requested = $this->invokeNonPublic(UserResource::class, 'resolveChildFields', [], OrganizationResource::class);
        static::assertSame(['name'], $requested);

        ApiQuery::parse(Request::create('/api/users', 'GET', []));

        $default = $this->invokeNonPublic(UserResource::class, 'resolveChildFields', [], OrganizationResource::class);
        static::assertSame(OrganizationResource::getDefaultFields(), $default);

        $allFallback = $this->invokeNonPublic(UserResource::class, 'resolveChildFields', [], ResourceWithNoDefaults::class);
        static::assertSame(ResourceWithNoDefaults::getAllFields(), $allFallback);
    }

    public function testApiResourceAdditionalBranchesForConstructorAndInternalHelpers(): void
    {
        $organization = Organization::query()->create(['name' => 'Org']);
        $user         = User::query()->create(['name' => 'Alice', 'organization_id' => $organization->id]);
        $user->setRelation('organization', $organization);

        ApiQuery::parse(Request::create('/api/users', 'GET', [
            'fields' => ['user' => ':all,counts'],
            'counts' => ['user' => 'posts'],
        ]));

        $allResource = new UserResource($user, false, ':all', ['name']);
        $array       = $allResource->toArray(Request::create('/api/users'));

        static::assertSame('user', $array['_type']);

        $loader = new class {
            public array $loaded = [];

            public function loadMissing(array $with): void
            {
                $this->loaded = $with;
            }
        };

        new UserResource($loader, true, ':all');
        static::assertNotEmpty($loader->loaded);

        static::assertSame([], UserResource::eagerLoadMapFor(['unknown']));
        static::assertSame([], CountFallbackResource::eagerLoadMapFor(['__count__:likes']));

        $countsPayload = $this->invokeNonPublic(new UserResource('invalid-owner'), 'resolveCountsPayload');
        static::assertSame([], $countsPayload);

        $metricValue = $this->invokeNonPublic(new UserResource($user), 'resolveFieldValue', '__count__:posts', Request::create('/'));
        static::assertInstanceOf(MissingValue::class, $metricValue);

        $attributeModel = new class extends \Illuminate\Database\Eloquent\Model {
            public $timestamps = false;
            protected $table   = 'users';
            protected $guarded = [];

            protected function magicAccessor(): \Illuminate\Database\Eloquent\Casts\Attribute
            {
                return \Illuminate\Database\Eloquent\Casts\Attribute::make(get: static fn () => 'magic');
            }
        };

        $attributeResolved = $this->invokeNonPublic(new UserResource($attributeModel), 'resolveSimpleProperty', 'magicAccessor');
        static::assertSame('magic', $attributeResolved);

        $issetResolved = $this->invokeNonPublic(new UserResource(new class {
            public function __isset(string $name): bool
            {
                return $name === 'dynamic';
            }

            public function __get(string $name): string
            {
                return 'value';
            }
        }), 'resolveSimpleProperty', 'dynamic');
        static::assertSame('value', $issetResolved);

        $user->setRelation('organization', null);
        $relationNull = $this->invokeNonPublic(new UserResource($user), 'resolveRelationValue', ['relation' => ['organization']], Request::create('/'));
        static::assertNull($relationNull);

        $user->setRelation('organization', $organization);
        $relationCallable = $this->invokeNonPublic(new UserResource($user), 'resolveRelationValue', [
            'relation' => ['organization'],
            'accessor' => static fn () => 'callable',
        ], Request::create('/'));
        static::assertSame('callable', $relationCallable);

        $countsWithFallback = CountFallbackResource::eagerLoadCountsFor();
        static::assertContains('likes', $countsWithFallback);

        $nonRecursiveMap = UserResource::eagerLoadMapFor(['raw_relation']);
        static::assertContains('organization', $nonRecursiveMap);

        $duplicateMap = UserResource::eagerLoadMapFor(['organization', 'organization', 'raw_relation']);
        static::assertSame(3, count(array_filter($duplicateMap, static fn ($entry) => $entry === 'organization')));

        $scoped = UserResource::eagerLoadMapFor(['posts']);
        $scope  = $scoped['posts'];

        $scope($user->owner());
        $scope($user->posts());
        $scope(Post::query());

        static::assertTrue(true);
    }
}

class MissingResourceTypeResource extends ApiResource
{
    protected static array $default = ['id'];

    public static function schema(): array
    {
        return ['id' => []];
    }
}

class ResourceWithNoDefaults extends ApiResource
{
    public const string RESOURCE_TYPE = 'empty';
    protected static array $default   = [];

    public static function schema(): array
    {
        return [
            'id'   => [],
            'name' => [],
        ];
    }
}

class CountFallbackResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'count-fallback';
    protected static array $default   = [];

    public static function schema(): array
    {
        return [
            '__count__:likes' => [
                'metric'   => 'count',
                'relation' => 'likes',
                'default'  => true,
            ],
        ];
    }
}

class NonApiResolvableResource extends JsonResource
{
    public function __construct(
        mixed $item,
        private bool $load = false,
        private ?array $fields = null,
        private ?array $excludedFields = null,
    ) {
        parent::__construct($item);
    }

    public function resolve($request = null): array
    {
        $name = is_object($this->resource) && property_exists($this->resource, 'resource')
            ? $this->resource->resource
            : (is_object($this->resource) && property_exists($this->resource, 'name') ? $this->resource->name : (string) $this->resource);

        return [
            'name' => 'wrapped:' . $name,
            'meta' => [$this->load, $this->fields, $this->excludedFields, $request instanceof Request ? $request->path() : null],
        ];
    }
}
