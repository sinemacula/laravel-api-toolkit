<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Exceptions\PreconditionFailedException;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Http\Concurrency\EntityTag;
use SineMacula\ApiToolkit\Http\Concurrency\IfMatch;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\Http\Enums\HttpMethod;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\Concerns\CreatesDocumentTables;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\AuditEntry;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableTagRepository;
use Tests\Fixtures\Repositories\CountryRepository;
use Tests\Fixtures\Repositories\DeferrableModelRepository;
use Tests\Fixtures\Repositories\DocumentRepository;
use Tests\Fixtures\Repositories\DummyRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\CappedPageUserResource;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\UnreachableCacheStore;
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
#[Group('database')]
final class ApiRepositoryTest extends TestCase
{
    use CreatesDeferredWriteTargets, CreatesDocumentTables, InteractsWithNonPublicMembers, RecordsStatements;

    /** @var string */
    private const string ALICE_EMAIL = 'alice@example.com';

    /** @var string */
    private const string BOB_EMAIL = 'bob@example.com';

    /** @var string The refusal of a locked read composed with request criteria */
    private const string CRITERIA_MESSAGE = '%s::findForUpdate() cannot apply request criteria; call it on a handle without withApiCriteria() or withApiFilters().';

    /** @var string The refusal of a locked read outside a transaction */
    private const string UNLOCKED_MESSAGE = '%s::findForUpdate() must run inside a transaction on the [%s] connection, or its lock is released at once.';

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

        self::assertFalse($this->getProperty($this->apiCriteriaOf($result), 'filtersOnly'));
    }

    /**
     * Test that withApiFilters composes filters-only criteria into a copy,
     * leaving the original.
     *
     * @return void
     */
    public function testWithApiFiltersComposesFiltersOnlyCriteriaIntoACopy(): void
    {
        $result = $this->repository->withApiFilters();

        self::assertNotSame($this->repository, $result);
        self::assertTrue($this->getProperty($this->apiCriteriaOf($result), 'filtersOnly'));

        self::assertFalse(
            $this->repository->getCriteria()->contains(fn ($c) => $c instanceof ApiCriteria),
        );
    }

    /**
     * Test that the last of withApiCriteria and withApiFilters called on a
     * handle leaves the only API criteria.
     *
     * @return void
     */
    public function testTheLastApiCompositionReplacesTheOther(): void
    {
        $filtered = $this->repository->withApiCriteria()->withApiFilters();

        self::assertCount(1, $filtered->getCriteria());
        self::assertTrue($this->getProperty($this->apiCriteriaOf($filtered), 'filtersOnly'));

        $paged = $this->repository->withApiFilters()->withApiCriteria();

        self::assertCount(1, $paged->getCriteria());
        self::assertFalse($this->getProperty($this->apiCriteriaOf($paged), 'filtersOnly'));
    }

    /**
     * Test that withApiFilters carries an already-set resource over, and that
     * naming one afterwards reaches the criteria.
     *
     * @return void
     */
    public function testWithApiFiltersCarriesTheNamedResource(): void
    {
        $after = $this->repository->withApiFilters()->usingResource(FilterableUserResource::class);

        self::assertSame(FilterableUserResource::class, $this->getProperty($this->apiCriteriaOf($after), 'customResourceClass'));

        $before = $this->repository->usingResource(UserResource::class)->withApiFilters();

        self::assertSame(UserResource::class, $this->getProperty($this->apiCriteriaOf($before), 'customResourceClass'));
    }

    /**
     * Test that a filters-only page past the offset cap is still refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testWithApiFiltersPagePastTheOffsetCapIsRefused(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 10);

        $this->parseRequest(new Request(['limit' => '5', 'page' => '4']));

        try {
            $this->repository->usingResource(UserResource::class)->withApiFilters()->paginate();
            self::fail('Expected the page to be refused.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame(['parameter' => 'page', 'pointer' => '', 'reason' => 'max_offset', 'limit' => 3, 'actual' => 4], $exception->getCustomMeta());
        }
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
     * Test that paginate ends the order on the qualified key.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testPaginateEndsTheOrderOnTheQualifiedKey(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->parseRequest(new Request(['limit' => '10']));

        DB::enableQueryLog();

        $this->repository->addScope(static function (Builder $query): void {
            $query->getQuery()->orderBy('name', 'desc');
        })->paginate();

        $queries = array_column(DB::getQueryLog(), 'query');
        $grammar = DB::connection()->getQueryGrammar();

        self::assertStringEndsWith(sprintf('order by %s desc, %s desc limit 10 offset 0', $grammar->wrap('name'), $grammar->wrap('users.id')), (string) end($queries));
    }

    /**
     * Test that a page past the offset cap is refused, leaving the handle it
     * was called on reusable.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testPagePastTheOffsetCapLeavesTheHandleReusable(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 10);

        $alice = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);

        $scoped = $this->repository->scopeById($alice->id);

        $this->parseRequest(new Request(['limit' => '5', 'page' => '4']));

        try {
            $scoped->paginate();
            self::fail('Expected the page to be refused.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame(['parameter' => 'page', 'pointer' => '', 'reason' => 'max_offset', 'limit' => 3, 'actual' => 4], $exception->getCustomMeta());
        }

        $this->parseRequest(new Request(['limit' => '5', 'page' => '1']));

        $page = $scoped->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $page);
        self::assertCount(2, $page);
    }

    /**
     * Test that a cursor read is not refused for a page past the offset cap.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testCursorReadIsNotRefusedForAPagePastTheOffsetCap(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 10);

        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->parseRequest(new Request(['limit' => '5', 'page' => '4', 'pagination' => 'cursor']));

        self::assertInstanceOf(CursorPaginator::class, $this->repository->paginate());
    }

    /**
     * Test that a read through the criteria that does not page is not refused
     * for a page past the offset cap.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testUnpagedCriteriaReadIsNotRefusedForAPagePastTheOffsetCap(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 10);

        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->parseRequest(new Request(['limit' => '5', 'page' => '4']));

        self::assertCount(1, $this->repository->usingResource(UserResource::class)->withApiCriteria()->get());
    }

    /**
     * Provide the pagination modes a page size is bounded in.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function paginationModeProvider(): iterable
    {
        yield 'offset' => [[]];
        yield 'cursor' => [['pagination' => 'cursor']];
    }

    /**
     * Test that a page with no size asked for is served at the resource's own
     * ceiling.
     *
     * @param  array<string, string>  $mode
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('paginationModeProvider')]
    public function testPaginateServesAnUnsizedPageAtTheResourceCeiling(array $mode): void
    {
        $this->seedThreeUsers();

        $this->parseRequest(new Request($mode));

        $page = $this->repository->usingResource(CappedPageUserResource::class)->paginate();

        self::assertTrue($page instanceof Paginator || $page instanceof CursorPaginator);
        self::assertSame(2, $page->perPage());
        self::assertCount(2, $page->items());
    }

    /**
     * Test that a page size at the resource's ceiling is served and one above
     * it is refused, naming that ceiling.
     *
     * @param  array<string, string>  $mode
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('paginationModeProvider')]
    public function testPaginateHoldsThePageSizeToTheResourceCeiling(array $mode): void
    {
        $this->seedThreeUsers();

        $repository = $this->repository->usingResource(CappedPageUserResource::class);

        $this->parseRequest(new Request(['limit' => '2', ...$mode]));

        $page = $repository->paginate();

        self::assertInstanceOf(\Countable::class, $page);
        self::assertCount(2, $page);

        $this->parseRequest(new Request(['limit' => '3', ...$mode]));

        try {
            $repository->paginate();
            self::fail('Expected the page size to be refused.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame(['parameter' => 'limit', 'pointer' => '', 'reason' => 'max_limit', 'limit' => 2, 'actual' => 3], $exception->getCustomMeta());
        }
    }

    /**
     * Test that the ceiling of the mapped resource applies, and a named
     * resource replaces it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testPaginateHoldsThePageSizeToTheResolvedResource(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, CappedPageUserResource::class);

        $this->seedThreeUsers();

        $this->parseRequest(new Request(['limit' => '3']));

        $page = $this->repository->usingResource(UserResource::class)->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $page);
        self::assertCount(3, $page);

        $this->expectException(QueryTooExpensiveException::class);

        $this->repository->usingResource(null)->paginate();
    }

    /**
     * Test that the offset cap counts its pages at the resource's ceiling.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testOffsetCapCountsPagesAtTheResourceCeiling(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 4);

        $this->seedThreeUsers();

        $repository = $this->repository->usingResource(CappedPageUserResource::class);

        $this->parseRequest(new Request(['page' => '3']));

        $page = $repository->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $page);
        self::assertCount(0, $page);

        $this->parseRequest(new Request(['page' => '4']));

        try {
            $repository->paginate();
            self::fail('Expected the page to be refused.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame(['parameter' => 'page', 'pointer' => '', 'reason' => 'max_offset', 'limit' => 3, 'actual' => 4], $exception->getCustomMeta());
        }
    }

    /**
     * Test that paginate seeks from the decoded cursor token.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testPaginateSeeksFromTheCursorToken(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $bob = User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);

        $this->parseRequest(new Request(['cursor' => (new Cursor(['users.id' => 1]))->encode()]));

        $result = $this->repository->paginate();

        self::assertInstanceOf(CursorPaginator::class, $result);
        self::assertCount(1, $result->items());
        self::assertInstanceOf(User::class, $result->items()[0]);
        self::assertSame($bob->id, $result->items()[0]->id);
    }

    /**
     * Test that a rejected paginate resets the handle it was called on.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRejectedPaginateLeavesTheHandleReusable(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);

        $scoped = $this->repository->scopeById($alice->id);

        $this->parseRequest(new Request(['cursor' => 'garbage']));

        try {
            $scoped->paginate();
            self::fail('Expected the cursor to be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('cursor', $exception->errors());
        }

        $this->parseRequest(new Request(['limit' => '10']));

        $page = $scoped->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $page);
        self::assertCount(2, $page);
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
     * Test that persist invalidates the cache of a cacheable repository.
     *
     * @return void
     */
    public function testPersistInvalidatesTheCacheOfACacheableRepository(): void
    {
        Config::set('cache.default', 'array');

        $tag = Tag::create(['name' => 'php']);

        assert($this->app !== null);

        $tags = $this->app->make(CacheableTagRepository::class);

        $tags->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($tags->persist($tag, ['name' => 'laravel']));
        self::assertSame(['laravel'], $tags->get()->pluck('name')->all()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that persist on a repository without a cache reports nothing.
     *
     * @return void
     */
    public function testPersistOnARepositoryWithoutACacheReportsNothing(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        Exceptions::fake();

        self::assertTrue($this->repository->persist($user, ['name' => 'Bob']));

        Exceptions::assertNothingReported();
    }

    /**
     * Test that persist invalidates the cache even when the write fails and
     * rolls back.
     *
     * @return void
     */
    public function testPersistInvalidatesTheCacheWhenTheWriteFails(): void
    {
        $tag = Tag::create(['name' => 'php']);

        assert($this->app !== null);

        $tags = $this->app->make(CacheableTagRepository::class);

        $tags->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::table('tags')->where('id', $tag->id)->update(['name' => 'laravel']);

        $persist = static fn (): bool => $tags->persist($tag, ['name' => 'symfony', 'posts' => 1.5]);

        $this->assertThrows($persist, \InvalidArgumentException::class, 'must be given models or their keys');

        self::assertSame(['laravel'], $tags->get()->pluck('name')->all()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that persist reports a failed cache flush and keeps the write.
     *
     * @return void
     */
    public function testPersistReportsAFailedCacheFlushAndKeepsTheWrite(): void
    {
        $tag = Tag::create(['name' => 'php']);

        $this->useUnreachableCacheStore();

        assert($this->app !== null);

        $tags = $this->app->make(CacheableTagRepository::class);

        Exceptions::fake();

        self::assertTrue($tags->persist($tag, ['name' => 'laravel']));
        self::assertSame('laravel', $tag->fresh()?->name);

        Exceptions::assertReported(static fn (\RuntimeException $exception): bool => $exception->getMessage() === UnreachableCacheStore::MESSAGE);
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
     * Test that scopeById scopes by the model key, qualified by its table.
     *
     * @return void
     */
    public function testScopeByIdScopesByTheQualifiedKey(): void
    {
        self::assertSame('users.id', $this->repository->scopeById(1)->getQuery()->wheres[0]['column']); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that scopeById and scopeByIds scope by a key not named id.
     *
     * @return void
     */
    public function testScopeByIdAndScopeByIdsScopeByAKeyNotNamedId(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(CountryRepository::class);

        $one  = $repository->scopeById('GB')->getQuery()->wheres[0]['column']; // @phpstan-ignore staticMethod.dynamicCall
        $many = $repository->scopeByIds(['GB', 'FR'])->getQuery()->wheres[0]['column']; // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame('countries.code', $one);
        self::assertSame('countries.code', $many);
    }

    /**
     * Test that scopeByIds qualifies a given column and passes a qualified one
     * through.
     *
     * @return void
     */
    public function testScopeByIdsQualifiesAGivenColumnAndPassesAQualifiedOneThrough(): void
    {
        $email = $this->repository->scopeByIds([self::ALICE_EMAIL], 'email')->getQuery()->wheres[0]['column']; // @phpstan-ignore staticMethod.dynamicCall
        $post  = $this->repository->scopeByIds([1], 'posts.id')->getQuery()->wheres[0]['column']; // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame('users.email', $email);
        self::assertSame('posts.id', $post);
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
     * Test that a repository with Cacheable serves a repeated read from the
     * cache without querying.
     *
     * @return void
     */
    public function testApiRepositorySubclassWithCacheableTraitServesARepeatedReadFromTheCache(): void
    {
        Config::set('cache.default', 'array');

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);

        assert($this->app !== null);

        $repository = $this->app->make(CacheableTagRepository::class);

        $firstRead  = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $secondRead = null;

        $statements = $this->recordStatements(static function () use ($repository, &$secondRead): void {
            $secondRead = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        });

        self::assertStatementCount(0, $statements);
        self::assertSame(['php', 'laravel'], $firstRead->pluck('name')->all());
        self::assertEquals($firstRead, $secondRead);
    }

    /**
     * Test that findForUpdate() reads the stored row afresh when the
     * precondition holds or none is given.
     *
     * @return void
     */
    public function testFindForUpdateReturnsTheStoredRowWhenThePreconditionHolds(): void
    {
        $stale = Document::create(['title' => 'Draft']);

        Document::query()->whereKey($stale->id)->update(['title' => 'Revised', 'version' => 4]);

        $current = (new Document)->setRawAttributes(['id' => $stale->id, 'version' => 4], true);
        $found   = $this->documents()->findForUpdate($stale->id, $this->ifMatch((string) EntityTag::for($current)));

        self::assertInstanceOf(Document::class, $found);
        self::assertSame('Revised', $found->title);
        self::assertSame('Revised', $this->documents()->findForUpdate($stale->id)->getAttribute('title'));
    }

    /**
     * Test that findForUpdate() reads its row under an update lock.
     *
     * @return void
     */
    public function testFindForUpdateLocksTheRowItReads(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $read     = null;

        $this->documents()->addScope(static function (Builder $query) use (&$read): void {
            $read = $query;
        })->findForUpdate($document->id);

        self::assertInstanceOf(EloquentBuilder::class, $read);
        self::assertTrue($read->getQuery()->lock);
    }

    /**
     * Test that findForUpdate() refuses a row whose tag the precondition does
     * not name.
     *
     * @return void
     */
    public function testFindForUpdateRefusesAStaleTag(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $tag      = (string) EntityTag::for($document);

        $document->update(['title' => 'Revised']);

        $this->expectException(PreconditionFailedException::class);

        $this->documents()->findForUpdate($document->id, $this->ifMatch($tag));
    }

    /**
     * Test that findForUpdate() answers a row the repository scopes out as not
     * found before checking the precondition.
     *
     * @return void
     */
    public function testFindForUpdateTreatsAScopedOutRowAsNotFound(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $other    = Document::create(['title' => 'Other']);

        $this->expectException(ModelNotFoundException::class);

        $this->documents()->scopeById($other->id)->findForUpdate($document->id, $this->ifMatch('"stale"'));
    }

    /**
     * Test that findForUpdate() refuses to apply request criteria.
     *
     * @return void
     */
    public function testFindForUpdateRefusesRequestCriteria(): void
    {
        $document = Document::create(['title' => 'Draft']);

        $this->expectExceptionObject(new \LogicException(sprintf(self::CRITERIA_MESSAGE, DocumentRepository::class)));

        $this->documents()->withApiCriteria()->findForUpdate($document->id);
    }

    /**
     * Test that findForUpdate() refuses to apply the request's filters.
     *
     * @return void
     */
    public function testFindForUpdateRefusesRequestFilters(): void
    {
        $document = Document::create(['title' => 'Draft']);

        $this->expectExceptionObject(new \LogicException(sprintf(self::CRITERIA_MESSAGE, DocumentRepository::class)));

        $this->documents()->withApiFilters()->findForUpdate($document->id);
    }

    /**
     * Test that findForUpdate() refuses to lock outside a transaction on the
     * model's own connection.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testFindForUpdateRefusesALockOutsideATransactionOnTheModelsConnection(): void
    {
        $this->useAuditConnection();

        $this->expectExceptionObject(new \LogicException(sprintf(self::UNLOCKED_MESSAGE, DeferrableModelRepository::class, 'audit')));

        $this->deferrableRepository(AuditEntry::class)->findForUpdate(1);
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createDocumentTables();
    }

    /**
     * Resolve the API criteria the given handle carries.
     *
     * @param  \Tests\Fixtures\Repositories\UserRepository  $repository
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     */
    private function apiCriteriaOf(UserRepository $repository): ApiCriteria
    {
        $criteria = $repository->getCriteria()->first(fn ($c) => $c instanceof ApiCriteria);

        self::assertInstanceOf(ApiCriteria::class, $criteria);

        return $criteria;
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

    /**
     * Seed three users.
     *
     * @return void
     */
    private function seedThreeUsers(): void
    {
        User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        User::create(['name' => 'Bob', 'email' => self::BOB_EMAIL]);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com']);
    }

    /**
     * Resolve a document repository.
     *
     * @return \Tests\Fixtures\Repositories\DocumentRepository
     */
    private function documents(): DocumentRepository
    {
        assert($this->app !== null);

        return $this->app->make(DocumentRepository::class);
    }

    /**
     * Read the precondition the given If-Match field sets.
     *
     * @param  string  $field
     * @return \SineMacula\ApiToolkit\Http\Concurrency\IfMatch
     */
    private function ifMatch(string $field): IfMatch
    {
        $ifMatch = IfMatch::fromRequest(Request::create('/', 'PATCH', server: ['HTTP_IF_MATCH' => $field]));

        assert($ifMatch !== null);

        return $ifMatch;
    }
}
