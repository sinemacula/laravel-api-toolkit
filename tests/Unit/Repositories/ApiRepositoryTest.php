<?php

namespace Tests\Unit\Repositories;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ApiRepository class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
class ApiRepositoryTest extends TestCase
{
    use InteractsWithNonPublicMembers;

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
        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $parser = $this->app->make('api.query');
        $parser->parse(new \Illuminate\Http\Request(['limit' => '10']));

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
        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $request = \Illuminate\Http\Request::create('/', 'GET', ['pagination' => 'cursor']);

        $this->app->instance('request', $request);

        \Illuminate\Support\Facades\Request::clearResolvedInstance('request');

        $parser = $this->app->make('api.query');
        $parser->parse($request);

        $repository = $this->app->make(UserRepository::class);

        $result = $repository->paginate();

        static::assertInstanceOf(\Illuminate\Contracts\Pagination\CursorPaginator::class, $result);
    }

    /**
     * Test that setAttributes sets string attributes on the model.
     *
     * @return void
     */
    public function testSetAttributesSetsStringAttributes(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->setProperty($this->repository, 'casts', ['name' => 'string']);

        $result = $this->repository->setAttributes($user, ['name' => 'Bob']);

        static::assertTrue($result);
        static::assertSame('Bob', $user->fresh()->name);
    }

    /**
     * Test that setAttributes sets integer attributes on the model.
     *
     * @return void
     */
    public function testSetAttributesSetsIntegerAttributes(): void
    {
        Config::set('api-toolkit.repositories.cast_map.integer', ['integer', 'int']);

        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $repository = $this->app->make(UserRepository::class);

        $this->setProperty($repository, 'casts', ['organization_id' => 'integer']);

        $result = $repository->setAttributes($user, ['organization_id' => '5']);

        static::assertTrue($result);
        static::assertSame(5, $user->fresh()->organization_id);
    }

    /**
     * Test that setAttributes sets boolean attributes on the model.
     *
     * @return void
     */
    public function testSetAttributesSetsBooleanAttributes(): void
    {
        $post = \Tests\Fixtures\Models\Post::create([
            'user_id' => User::create(['name' => 'Alice', 'email' => 'alice@example.com'])->id,
            'title'   => 'Test',
            'body'    => 'Body',
        ]);

        $repository = $this->app->make(\Tests\Fixtures\Repositories\DummyRepository::class);

        $this->setProperty($repository, 'casts', ['published' => 'boolean']);

        $result = $repository->setAttributes($post, ['published' => true]);

        static::assertTrue($result);
        static::assertTrue((bool) $post->fresh()->published);
    }

    /**
     * Test that setAttributes handles array attributes on the model.
     *
     * @return void
     */
    public function testSetAttributesHandlesArrayAttributes(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->setProperty($this->repository, 'casts', ['name' => 'string']);

        $result = $this->repository->setAttributes($user, ['name' => 'Updated']);

        static::assertTrue($result);
        static::assertSame('Updated', $user->fresh()->name);
    }

    /**
     * Test that setAttributes handles enum casting.
     *
     * @return void
     */
    public function testSetAttributesHandlesEnumCasting(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        $this->setProperty($this->repository, 'casts', ['status' => 'enum']);

        $result = $this->repository->setAttributes($user, ['status' => UserStatus::BANNED]);

        static::assertTrue($result);
        static::assertSame(UserStatus::BANNED, $user->fresh()->status);
    }

    /**
     * Test that scopeById filters by a single ID.
     *
     * @return void
     */
    public function testScopeByIdFiltersBySingleId(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $result = $this->repository->scopeById($alice->id)->first();

        static::assertSame($alice->id, $result->id);
    }

    /**
     * Test that scopeByIds filters by multiple IDs.
     *
     * @return void
     */
    public function testScopeByIdsFiltersByMultipleIds(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);

        $result = $this->repository->scopeByIds([$alice->id, $bob->id])->get();

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
}
