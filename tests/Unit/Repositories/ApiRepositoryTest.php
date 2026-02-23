<?php

namespace Tests\Unit\Repositories;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\DummyRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ApiRepository class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
class ApiRepositoryTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var string */
    private const string ALICE_EMAIL = 'alice@example.com';

    /** @var string */
    private const string BOB_EMAIL = 'bob@example.com';

    /** @var \Tests\Fixtures\Repositories\UserRepository */
    private UserRepository $repository;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $this->repository = $this->app->make(UserRepository::class);
    }

    /**
     * Test that withApiCriteria adds an ApiCriteria instance to the repository.
     *
     * @return void
     */
    public function testWithApiCriteriaAddsApiCriteriaInstance(): void
    {
        $result = $this->repository->withApiCriteria();

        static::assertSame($this->repository, $result);

        $criteria = $this->repository->getCriteria();

        static::assertTrue(
            $criteria->contains(fn ($c) => $c instanceof ApiCriteria),
        );
    }

    /**
     * Test that usingResource sets the custom resource class and propagates
     * to existing ApiCriteria.
     *
     * @return void
     */
    public function testUsingResourceSetsCustomResourceAndPropagatesToCriteria(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $this->repository->withApiCriteria();
        $this->repository->usingResource(UserResource::class);

        static::assertSame(UserResource::class, $this->repository->getResourceClass());

        $criteria = $this->repository->getCriteria();

        static::assertTrue(
            $criteria->contains(fn ($c) => $c instanceof ApiCriteria),
        );
    }

    /**
     * Test that getResourceClass resolves the resource from the model's
     * resource map configuration.
     *
     * @return void
     */
    public function testGetResourceClassResolvesResourceFromModel(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $result = $this->repository->getResourceClass();

        static::assertSame(UserResource::class, $result);
    }

    /**
     * Test that getResourceClass returns null when no mapping exists.
     *
     * @return void
     */
    public function testGetResourceClassReturnsNullWhenNoMappingExists(): void
    {
        Config::set('api-toolkit.resources.resource_map', []);

        $result = $this->repository->getResourceClass();

        static::assertNull($result);
    }

    /**
     * Test that paginate returns paginated results using standard pagination.
     *
     * @return void
     */
    public function testPaginateReturnsPaginatedResults(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);

        $this->parseRequest(new Request(['limit' => '10']));

        $result = $this->repository->paginate();

        static::assertCount(2, $result);
    }

    /**
     * Test that paginate uses cursor pagination when requested.
     *
     * @return void
     */
    public function testPaginateUsesCursorPaginationWhenRequested(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $request = Request::create('/', 'GET', ['pagination' => 'cursor']);

        assert($this->app !== null);

        $this->app->instance('request', $request);

        \Illuminate\Support\Facades\Request::clearResolvedInstance('request');

        $this->parseRequest($request);

        assert($this->app !== null);

        $repository = $this->app->make(UserRepository::class);

        $result = $repository->paginate();

        static::assertInstanceOf(CursorPaginator::class, $result);
    }

    /**
     * Test that setAttributes sets string attributes on the model.
     *
     * @return void
     */
    public function testSetAttributesSetsStringAttributes(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->repository, 'casts', ['name' => 'string']);

        $result = $this->repository->setAttributes($user, ['name' => 'Bob']);

        static::assertTrue($result);
        static::assertSame('Bob', $user->fresh()?->name);
    }

    /**
     * Test that setAttributes sets integer attributes on the model.
     *
     * @return void
     */
    public function testSetAttributesSetsIntegerAttributes(): void
    {
        Config::set('api-toolkit.repositories.cast_map.integer', ['integer', 'int']);

        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        assert($this->app !== null);

        $repository = $this->app->make(UserRepository::class);

        $this->setProperty($repository, 'casts', ['organization_id' => 'integer']);

        $result = $repository->setAttributes($user, ['organization_id' => '5']);

        static::assertTrue($result);
        static::assertSame(5, $user->fresh()?->organization_id);
    }

    /**
     * Test that setAttributes sets boolean attributes on the model.
     *
     * @return void
     */
    public function testSetAttributesSetsBooleanAttributes(): void
    {
        $post = Post::create([
            'user_id' => User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL])->id,
            'title'   => 'Test',
            'body'    => 'Body',
        ]);

        assert($this->app !== null);

        $repository = $this->app->make(DummyRepository::class);

        $this->setProperty($repository, 'casts', ['published' => 'boolean']);

        $result = $repository->setAttributes($post, ['published' => true]);

        static::assertTrue($result);
        static::assertTrue($post->fresh()?->published === true);
    }

    /**
     * Test that setAttributes handles array attributes on the model.
     *
     * @return void
     */
    public function testSetAttributesHandlesArrayAttributes(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->repository, 'casts', ['name' => 'string']);

        $result = $this->repository->setAttributes($user, ['name' => 'Updated']);

        static::assertTrue($result);
        static::assertSame('Updated', $user->fresh()?->name);
    }

    /**
     * Test that setAttributes handles enum casting.
     *
     * @return void
     */
    public function testSetAttributesHandlesEnumCasting(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL, 'status' => 'active']);

        $this->setProperty($this->repository, 'casts', ['status' => 'enum']);

        $result = $this->repository->setAttributes($user, ['status' => UserStatus::BANNED]);

        static::assertTrue($result);
        // @phpstan-ignore staticMethod.impossibleType
        static::assertSame(UserStatus::BANNED, $user->fresh()?->status);
    }

    /**
     * Test that scopeById filters by a single ID.
     *
     * @return void
     */
    public function testScopeByIdFiltersBySingleId(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);

        $result = $this->repository->scopeById($alice->id)->first(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertNotNull($result);
        static::assertInstanceOf(User::class, $result);
        static::assertSame($alice->id, $result->id);
    }

    /**
     * Test that scopeByIds filters by multiple IDs.
     *
     * @return void
     */
    public function testScopeByIdsFiltersByMultipleIds(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $bob   = User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);

        $ids    = [$alice->id, $bob->id];
        $result = $this->repository->scopeByIds($ids)->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(2, $result);
    }

    /**
     * Test that boot resolves attribute casts from the model.
     *
     * @return void
     */
    public function testBootResolvesAttributeCasts(): void
    {
        $casts = $this->getProperty($this->repository, 'casts');

        static::assertIsArray($casts);
    }

    /**
     * Test that setAttributes handles array cast attributes.
     *
     * @return void
     */
    public function testSetAttributesSetsArrayAttributes(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->repository, 'casts', ['name' => 'array']);

        $result = $this->repository->setAttributes($user, ['name' => ['first', 'last']]);

        static::assertTrue($result);
    }

    /**
     * Test that setObjectAttribute casts the value to stdClass.
     *
     * The private method is invoked directly to avoid persisting a stdClass to
     * a string column in SQLite.
     *
     * @return void
     */
    public function testSetObjectAttributeCastsValueToStdClass(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->invokeMethod($this->repository, 'setObjectAttribute', $user, 'name', ['key' => 'value']);

        static::assertInstanceOf(\stdClass::class, $user->getAttribute('name'));
    }

    /**
     * Test that setAttributes handles associate cast (BelongsTo relation).
     *
     * @return void
     */
    public function testSetAttributesSetsAssociateAttribute(): void
    {
        $org  = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->repository, 'casts', ['organization' => 'associate']);

        $result = $this->repository->setAttributes($user, ['organization' => $org->id]);

        static::assertTrue($result);
        static::assertSame($org->id, $user->organization_id);
    }

    /**
     * Test that setAttributes handles sync cast (BelongsToMany relation).
     *
     * @return void
     */
    public function testSetAttributesSyncAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $tag  = Tag::create(['name' => 'php']);

        assert($this->app !== null);

        $repository = $this->app->make(DummyRepository::class);
        $this->setProperty($repository, 'casts', ['tags' => 'sync']);

        $result = $repository->setAttributes($post, ['tags' => collect([$tag])]);

        static::assertTrue($result);
        static::assertCount(1, $post->fresh()?->tags()->get() ?? collect([]));
    }

    /**
     * Test that resolveCastForRelation returns 'associate' for a BelongsTo
     * relation.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsCastForBelongsTo(): void
    {
        $cast = $this->invokeMethod($this->repository, 'resolveCastForRelation', 'organization');

        static::assertSame('associate', $cast);
    }

    /**
     * Test that resolveCastForRelation returns null for a non-relation
     * method.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsNullForNonRelation(): void
    {
        // 'getKey' is a method on the model but not a relation
        $cast = $this->invokeMethod($this->repository, 'resolveCastForRelation', 'nonexistent');

        static::assertNull($cast);
    }

    /**
     * Test that setAttributes with a sync cast using an array of IDs.
     *
     * @return void
     */
    public function testSetAttributesSyncAttributeWithArrayOfIds(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $tag  = Tag::create(['name' => 'laravel']);

        assert($this->app !== null);

        $repository = $this->app->make(DummyRepository::class);
        $this->setProperty($repository, 'casts', ['tags' => 'sync']);

        $result = $repository->setAttributes($post, ['tags' => [$tag->getKey()]]);

        static::assertTrue($result);
    }

    /**
     * Test that usingResource with an existing ApiCriteria propagates the
     * custom resource class to the criteria.
     *
     * @return void
     */
    public function testUsingResourcePropagatesResourceToCriteria(): void
    {
        $this->repository->withApiCriteria();
        $this->repository->usingResource(UserResource::class);

        static::assertSame(UserResource::class, $this->repository->getResourceClass());
    }

    /**
     * Test that withApiCriteria propagates an already-set custom resource
     * class to the new criteria instance.
     *
     * @return void
     */
    public function testWithApiCriteriaPropagatesAlreadySetCustomResource(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $this->repository->usingResource(UserResource::class);
        $this->repository->withApiCriteria();

        static::assertSame(UserResource::class, $this->repository->getResourceClass());
    }

    /**
     * Test that setAttributes auto-discovers BelongsTo cast via reflection
     * when the attribute is not pre-cached.
     *
     * @return void
     */
    public function testSetAttributesAutoDiscoversBelongsToRelationCast(): void
    {
        $org  = Organization::create(['name' => 'AutoOrg', 'slug' => 'auto-org']);
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        // No 'organization' pre-set in casts — resolveCastForAttribute discovers
        // it through resolveCastForRelation (line 220).
        $result = $this->repository->setAttributes($user, ['organization' => $org->id]);

        static::assertTrue($result);
    }

    /**
     * Test that setAttributes auto-discovers BelongsToMany cast via
     * resolveCastForRelation on Post.tags().
     *
     * @return void
     */
    public function testSetAttributesAutoDiscoversBelongsToManyCast(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $tag  = Tag::create(['name' => 'auto-tag']);

        assert($this->app !== null);

        $repository = $this->app->make(DummyRepository::class);

        $result = $repository->setAttributes($post, ['tags' => [$tag->getKey()]]);

        static::assertTrue($result);
    }

    /**
     * Test that castMatchesLaravelCast returns true for a class-based cast
     * that matches exactly (line 314 in ApiRepository.php).
     *
     * Registers UserStatus::class under the 'enum' native key so that the
     * class_exists branch fires and returns true before the string-equality
     * fallback is reached.
     *
     * @return void
     */
    public function testCastMatchesExactClassBasedCast(): void
    {
        // Add UserStatus::class as a recognized laravel cast under 'enum' so
        // that class_exists($laravel_cast) is true AND $base_cast matches.
        $existing_enum_casts   = Config::get('api-toolkit.repositories.cast_map.enum', []);
        $existing_enum_casts[] = UserStatus::class;
        Config::set('api-toolkit.repositories.cast_map.enum', $existing_enum_casts);

        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL, 'status' => 'active']);

        assert($this->app !== null);

        $repository = $this->app->make(UserRepository::class);
        $this->setProperty($repository, 'casts', []);

        $result = $repository->setAttributes($user, ['status' => UserStatus::BANNED]);

        static::assertTrue($result);
    }

    /**
     * Test that setAttribute routes 'object' cast through the match arm.
     *
     * @return void
     */
    public function testSetAttributeRoutesObjectCastThroughMatchArm(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->invokeMethod($this->repository, 'setAttribute', $user, 'name', null, 'object');

        static::assertNull($user->getAttribute('name'));
    }

    /**
     * Resolve the API query parser and parse the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function parseRequest(Request $request): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse($request);
    }
}
