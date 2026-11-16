<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\Http\Enums\HttpMethod;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableTagRepository;
use Tests\Fixtures\Repositories\DummyRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
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
final class ApiRepositoryTest extends TestCase
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
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $this->repository = $this->app->make(UserRepository::class);
    }

    /**
     * Test that withApiCriteria composes into a copy, leaving the original.
     *
     * @return void
     */
    public function testWithApiCriteriaComposesIntoACopy(): void
    {
        $result = $this->repository->withApiCriteria();

        self::assertNotSame($this->repository, $result);

        self::assertTrue(
            $result->getCriteria()->contains(fn ($c) => $c instanceof ApiCriteria),
        );

        self::assertFalse(
            $this->repository->getCriteria()->contains(fn ($c) => $c instanceof ApiCriteria),
        );
    }

    /**
     * Test that usingResource sets the resource and propagates it to criteria.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testUsingResourceSetsCustomResourceAndPropagatesToCriteria(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $scoped = $this->repository->withApiCriteria()->usingResource(UserResource::class);

        self::assertSame(UserResource::class, $scoped->getResourceClass());

        self::assertTrue(
            $scoped->getCriteria()->contains(fn ($c) => $c instanceof ApiCriteria),
        );
    }

    /**
     * Test that usingResource skips criteria that are not ApiCriteria.
     *
     * @return void
     */
    public function testUsingResourceSkipsNonApiCriteria(): void
    {
        $scoped = $this->repository->withApiCriteria();

        $scoped->pushCriteria(self::createStub(CriteriaInterface::class));

        $result = $scoped->usingResource(UserResource::class);

        // Naming the resource mutates the handle instead of composing a copy
        self::assertSame($scoped, $result);

        $criteria = $scoped->getCriteria()->first(fn ($c) => $c instanceof ApiCriteria);

        self::assertInstanceOf(ApiCriteria::class, $criteria);
        self::assertSame(UserResource::class, $this->getProperty($criteria, 'customResourceClass'));
    }

    /**
     * Test that getResourceClass resolves the resource from the resource map.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testGetResourceClassResolvesResourceFromModel(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $result = $this->repository->getResourceClass();

        self::assertSame(UserResource::class, $result);
    }

    /**
     * Test that getResourceClass returns null when no mapping exists.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testGetResourceClassReturnsNullWhenNoMappingExists(): void
    {
        Config::set('api-toolkit.resources.resource_map', []);

        $result = $this->repository->getResourceClass();

        self::assertNull($result);
    }

    /**
     * Test that a subclass override of the metadata cache writer is honoured.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testResourceResolutionUsesSubclassMetadataCacheWriterOverride(): void
    {
        assert($this->app !== null);

        $repository = new class ($this->app) extends UserRepository {
            /** @var bool Whether the overridden writer accessor was invoked. */
            public bool $writerAccessed = false;

            /**
             * Record that the accessor was invoked and return the real writer.
             *
             * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
             */
            #[\Override]
            protected function metadataCacheWriter(): MetadataCacheWriter
            {
                $this->writerAccessed = true;

                return $this->app->make(MetadataCacheWriter::class);
            }
        };

        $repository->getResourceClass();

        self::assertTrue($repository->writerAccessed);
    }

    /**
     * Test that paginate returns paginated results using standard pagination.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testPaginateReturnsPaginatedResults(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);

        $this->parseRequest(new Request(['limit' => '10']));

        $result = $this->repository->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $result);
        self::assertCount(2, $result);
    }

    /**
     * Test that paginate uses cursor pagination when requested.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testPaginateUsesCursorPaginationWhenRequested(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $request = Request::create('/', HttpMethod::GET->getVerb(), ['pagination' => 'cursor']);

        assert($this->app !== null);

        $this->app->instance('request', $request);

        \Illuminate\Support\Facades\Request::clearResolvedInstance('request');

        $this->parseRequest($request);

        assert($this->app !== null);

        $repository = $this->app->make(UserRepository::class);

        $result = $repository->paginate();

        self::assertInstanceOf(CursorPaginator::class, $result);
    }

    /**
     * Test that persist writes uncast columns on an existing model.
     *
     * @return void
     */
    public function testPersistWritesUncastColumnsOnUpdate(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        self::assertTrue($this->repository->persist($user, ['name' => 'Bob', 'email' => self::BOB_EMAIL]));

        $fresh = $user->fresh();

        self::assertNotNull($fresh);
        self::assertSame('Bob', $fresh->name);
        self::assertSame(self::BOB_EMAIL, $fresh->email);
    }

    /**
     * Test that persist writes uncast columns when creating a model.
     *
     * @return void
     */
    public function testPersistWritesUncastColumnsOnCreate(): void
    {
        $user = new User;

        self::assertTrue($this->repository->persist($user, ['name' => 'Carol', 'email' => 'carol@example.com']));

        $fresh = $user->fresh();

        self::assertNotNull($fresh);
        self::assertSame('Carol', $fresh->name);
        self::assertSame('carol@example.com', $fresh->email);
    }

    /**
     * Test that persist accepts the attributes as a collection.
     *
     * @return void
     */
    public function testPersistAcceptsACollectionOfAttributes(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        self::assertTrue($this->repository->persist($user, collect(['name' => 'Bob', 'status' => UserStatus::BANNED])));

        $fresh = $user->fresh();

        self::assertNotNull($fresh);
        self::assertSame('Bob', $fresh->name);
        self::assertSame(UserStatus::BANNED, $fresh->getAttribute('status'));
    }

    /**
     * Test that persist associates a BelongsTo relation and syncs a
     * BelongsToMany relation.
     *
     * @return void
     */
    public function testPersistWritesRelations(): void
    {
        $org  = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $tag  = Tag::create(['name' => 'php']);

        assert($this->app !== null);

        $posts = $this->app->make(DummyRepository::class);

        self::assertTrue($this->repository->persist($user, ['organization' => $org->id]));
        self::assertTrue($posts->persist($post, ['tags' => collect([$tag]), 'published' => true]));

        self::assertSame($org->id, $user->fresh()?->organization_id);
        self::assertTrue($post->fresh()?->published);
        self::assertSame(['php'], $post->tags()->pluck('name')->all());
    }

    /**
     * Test that persist refuses a relation it cannot write.
     *
     * @return void
     */
    public function testPersistRefusesAnUnwritableRelation(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Attribute "posts" on %s is a HasMany relation, which persist() cannot write', User::class));

        $this->repository->persist($user, ['posts' => [1]]);
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

        self::assertNotNull($result);
        self::assertInstanceOf(User::class, $result);
        self::assertSame($alice->id, $result->id);
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

        self::assertCount(2, $result);
    }

    /**
     * Test that usingResource propagates the resource to every ApiCriteria.
     *
     * @return void
     */
    public function testUsingResourceUpdatesExistingApiCriteriaInstances(): void
    {
        $scoped = $this->repository->withApiCriteria()->usingResource(UserResource::class);

        $criteria = $scoped->getCriteria()->first(fn ($c) => $c instanceof ApiCriteria);

        self::assertInstanceOf(ApiCriteria::class, $criteria);
        self::assertSame(UserResource::class, $this->getProperty($criteria, 'customResourceClass'));
    }

    /**
     * Test that withApiCriteria carries an already-set resource over.
     *
     * @return void
     */
    public function testWithApiCriteriaSetsResourceOnNewCriteriaInstance(): void
    {
        $scoped = $this->repository->usingResource(UserResource::class)->withApiCriteria();

        $criteria = $scoped->getCriteria()->first(fn ($c) => $c instanceof ApiCriteria);

        self::assertInstanceOf(ApiCriteria::class, $criteria);
        self::assertSame(UserResource::class, $this->getProperty($criteria, 'customResourceClass'));
    }

    /**
     * Test that paginate applies registered criteria to the query.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testPaginateAppliesCriteria(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);

        $this->parseRequest(new Request([
            'filters' => json_encode(['name' => 'Alice']),
            'limit'   => '10',
        ]));

        $result = $this->repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $result);
        self::assertCount(1, $result);
        self::assertInstanceOf(User::class, $result[0]);
        self::assertSame('Alice', $result[0]->name);
    }

    /**
     * Test that paginate applies registered scopes to the query.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testPaginateAppliesScopes(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);

        $this->parseRequest(new Request(['limit' => '10']));

        $result = $this->repository
            ->withApiCriteria()
            ->scopeById($alice->id)
            ->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $result);
        self::assertCount(1, $result);
        self::assertInstanceOf(User::class, $result[0]);
        self::assertSame($alice->id, $result[0]->id);
    }

    /**
     * Test that paginate appends the current query string to pagination URLs.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testPaginateAppendsRequestQueryToPaginationUrls(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $request = Request::create('/', HttpMethod::GET->getVerb(), ['limit' => '1', 'marker' => 'xyz']);

        assert($this->app !== null);

        $this->app->instance('request', $request);

        \Illuminate\Support\Facades\Request::clearResolvedInstance('request');

        $this->parseRequest($request);

        assert($this->app !== null);

        $repository = $this->app->make(UserRepository::class);

        /** @var \Illuminate\Pagination\LengthAwarePaginator<int, \Tests\Fixtures\Models\User> $result */
        $result = $repository->paginate();

        self::assertStringContainsString('marker=xyz', $result->url(1));
    }

    /**
     * Test that scopeByIds de-duplicates the given ids.
     *
     * @return void
     */
    public function testScopeByIdsDeduplicatesIds(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        DB::enableQueryLog();

        $result = $this->repository->scopeByIds([$alice->id, $alice->id])->get(); // @phpstan-ignore staticMethod.dynamicCall

        $select = collect(DB::getQueryLog())->last(
            fn (array $query): bool => str_starts_with($query['query'], 'select'),
        );

        DB::disableQueryLog();

        self::assertCount(1, $result);
        self::assertNotNull($select);
        self::assertSame([$alice->id], $select['bindings']);
    }

    /**
     * Test that naming a resource reaches the criteria the handle carries.
     *
     * @return void
     */
    public function testUsingResourceReachesTheCriteriaTheHandleCarries(): void
    {
        $scoped = $this->repository->withApiCriteria()->usingResource(UserResource::class);

        $criteria = $scoped->getCriteria()->first(fn ($c) => $c instanceof ApiCriteria);

        self::assertInstanceOf(ApiCriteria::class, $criteria);
        self::assertSame(UserResource::class, $this->getProperty($criteria, 'customResourceClass'));
    }

    /**
     * Test that a named resource outranks the one the model maps to.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testANamedResourceOutranksTheMappedOne(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, FilterableUserResource::class);

        $named = $this->repository->usingResource(UserResource::class);

        self::assertSame(UserResource::class, $named->getResourceClass());

        $criteria = $named->withApiCriteria()->getCriteria()->first(fn ($c) => $c instanceof ApiCriteria);

        self::assertInstanceOf(ApiCriteria::class, $criteria);
        self::assertSame(UserResource::class, $this->getProperty($criteria, 'customResourceClass'));
    }

    /**
     * Test that a repository without Cacheable reads from the database.
     *
     * @return void
     */
    public function testApiRepositorySubclassWorksWithoutCacheableTrait(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $result);
        self::assertSame('Alice', $result->first()?->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that a repository with Cacheable serves cached data on later reads.
     *
     * @return void
     */
    public function testApiRepositorySubclassWithCacheableTraitReturnsCachedData(): void
    {
        Config::set('cache.default', 'array');

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);

        assert($this->app !== null);

        $repository = $this->app->make(CacheableTagRepository::class);

        $firstRead  = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $secondRead = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $firstRead);
        self::assertCount(2, $secondRead);
        self::assertTrue($repository->getCacheStatus()->isPopulated());
    }

    /**
     * Resolve the API query parser and parse the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function parseRequest(Request $request): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse($request);
    }
}
