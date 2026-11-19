<?php

declare(strict_types = 1);

namespace Tests\Integration\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\PageOrder;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\ImportRow;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CountryRepository;
use Tests\Fixtures\Repositories\ImportRowRepository;
use Tests\Fixtures\Repositories\LogRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\LogResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Integration tests for ApiRepository with a real database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(PageOrder::class)]
final class ApiRepositoryIntegrationTest extends TestCase
{
    /** @var \Tests\Fixtures\Repositories\UserRepository */
    private UserRepository $repository;

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        /** @var \Tests\Fixtures\Repositories\UserRepository $repository */
        $repository = $this->app->make(UserRepository::class);

        $this->repository = $repository->usingResource(UserResource::class);

        $this->seedUsers();
    }

    /**
     * Test that paginate returns a paginated collection.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testPaginateReturnsPaginatedCollection(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb(), ['limit' => '2']);
        ApiQuery::parse($request);

        $results = $this->repository->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $results);
        self::assertCount(2, $results);
    }

    /**
     * Test that persist writes uncast columns on an existing model.
     *
     * @return void
     */
    public function testPersistWritesUncastColumnsOnUpdate(): void
    {
        /** @var \Tests\Fixtures\Models\User $user */
        $user = User::where('name', 'Alice')->first();

        self::assertTrue($this->repository->persist($user, ['name' => 'Alice Updated', 'email' => 'alice.updated@example.com']));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Alice Updated', 'email' => 'alice.updated@example.com']);
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

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Carol', 'email' => 'carol@example.com']);
    }

    /**
     * Test that scopeById returns the correct record.
     *
     * @return void
     */
    public function testScopeByIdReturnsCorrectRecord(): void
    {
        /** @var \Tests\Fixtures\Models\User $user */
        $user = User::where('name', 'Alice')->first();

        /** @var \Tests\Fixtures\Models\User|null $result */
        $result = $this->repository->scopeById($user->id)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertNotNull($result);
        self::assertSame('Alice', $result->name);
    }

    /**
     * Test that scopeByIds returns the correct records.
     *
     * @return void
     */
    public function testScopeByIdsReturnsCorrectRecords(): void
    {
        /** @var \Tests\Fixtures\Models\User $alice */
        $alice = User::where('name', 'Alice')->first();

        /** @var \Tests\Fixtures\Models\User $bob */
        $bob = User::where('name', 'Bob')->first();

        $results = $this->repository->scopeByIds([$alice->id, $bob->id])->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $results);
        self::assertTrue($results->pluck('name')->contains('Alice'));
        self::assertTrue($results->pluck('name')->contains('Bob'));
    }

    /**
     * Test that scopeById reads a UUID-keyed model by its key.
     *
     * @return void
     */
    public function testScopeByIdReadsAUuidKeyedModel(): void
    {
        $target = Log::create(['level' => 'info', 'message' => 'Target']);

        Log::create(['level' => 'info', 'message' => 'Other']);

        /** @var \Tests\Fixtures\Models\Log|null $result */
        $result = app(LogRepository::class)->scopeById($target->id)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame('Target', $result?->message);
    }

    /**
     * Test that scopeById reads a string-keyed model by its key, with or
     * without the key column named.
     *
     * @return void
     */
    public function testScopeByIdReadsAStringKeyedModelWithOrWithoutTheColumn(): void
    {
        Country::create(['code' => 'GB', 'name' => 'United Kingdom']);
        Country::create(['code' => 'FR', 'name' => 'France']);

        $repository = app(CountryRepository::class);

        self::assertSame(['United Kingdom'], $repository->scopeById('GB')->pluck('name')->all()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame(['France'], $repository->scopeById('FR', 'code')->pluck('name')->all()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that scopeByIds with no ids reads nothing.
     *
     * @return void
     */
    public function testScopeByIdsWithNoIdsReadsNothing(): void
    {
        self::assertCount(0, $this->repository->scopeByIds([])->get()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that offset pages over UUID-keyed rows tied on the sorted column
     * return every row once.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testOffsetPagesOverTiedUuidKeyedRowsReturnEveryRowOnce(): void
    {
        $ids = [];

        foreach (['error', 'info', 'info', 'error', 'info'] as $level) {
            $ids[] = Log::create(['level' => $level, 'message' => $level])->id;
        }

        $repository = app(LogRepository::class)->usingResource(LogResource::class);
        $read       = [];

        foreach ([1, 2, 3] as $page) {

            ApiQuery::parse(Request::create('/test', HttpMethod::GET->getVerb(), ['order' => 'level', 'limit' => '2', 'page' => (string) $page]));

            $results = $repository->withApiCriteria()->paginate();

            self::assertInstanceOf(LengthAwarePaginator::class, $results);

            foreach ($results->items() as $log) {

                self::assertInstanceOf(Log::class, $log);

                $read[] = [$log->level, $log->id];
            }
        }

        self::assertSame(['error', 'error', 'info', 'info', 'info'], array_column($read, 0));
        self::assertEqualsCanonicalizing($ids, array_column($read, 1));
    }

    /**
     * Test that withApiCriteria applies criteria to query.
     *
     * @return void
     */
    public function testWithApiCriteriaAppliesCriteriaToQuery(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb(), [
            'filters' => json_encode(['name' => 'Alice']),
        ]);
        ApiQuery::parse($request);

        $results = $this->repository->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Alice', $first->name);
    }

    /**
     * Test that offset pages over a grouped scope return every group once.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testOffsetPagesOverAGroupedScopeReturnEveryGroupOnce(): void
    {
        $groups = [];

        foreach ([1, 2, 3] as $page) {

            ApiQuery::parse(Request::create('/test', HttpMethod::GET->getVerb(), ['limit' => '1', 'page' => (string) $page]));

            $results = $this->groupedByStatus()->paginate();

            self::assertInstanceOf(LengthAwarePaginator::class, $results);

            $groups = array_merge($groups, $results->items());
        }

        self::assertSame([['active', 2], ['inactive', 1]], array_map($this->statusCount(...), $groups));
    }

    /**
     * Test that cursor pages over a grouped scope return every group once.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testCursorPagesOverAGroupedScopeReturnEveryGroupOnce(): void
    {
        $groups = [];
        $cursor = '';

        do {

            ApiQuery::parse(Request::create('/test', HttpMethod::GET->getVerb(), ['pagination' => 'cursor', 'limit' => '1', 'cursor' => $cursor]));

            $page = $this->groupedByStatus()->paginate();

            self::assertInstanceOf(CursorPaginator::class, $page);

            $groups = array_merge($groups, $page->items());
            $cursor = $page->nextCursor()?->encode();
        } while ($cursor !== null);

        self::assertSame([['active', 2], ['inactive', 1]], array_map($this->statusCount(...), $groups));
    }

    /**
     * Test that a distinct projection without the key pages without an engine
     * error.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testDistinctProjectionWithoutTheKeyPages(): void
    {
        ApiQuery::parse(Request::create('/test', HttpMethod::GET->getVerb(), ['limit' => '10']));

        $results = $this->repository
            ->addScope(static function (Builder $query): void {
                $query->getQuery()->distinct()->select('status');
            })
            ->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $results);
        self::assertCount(2, $results->items());
    }

    /**
     * Test that a model whose table lacks its key column is offset paged
     * without the key.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testModelWithoutAKeyColumnOffsetPagesWithoutTheKey(): void
    {
        ImportRow::query()->insert([['reference' => 'r-1', 'payload' => 'p'], ['reference' => 'r-2', 'payload' => 'p'], ['reference' => 'r-3', 'payload' => 'p']]);

        ApiQuery::parse(Request::create('/test', HttpMethod::GET->getVerb(), ['limit' => '2', 'page' => '2']));

        $results = app(ImportRowRepository::class)->paginate();

        self::assertInstanceOf(LengthAwarePaginator::class, $results);
        self::assertCount(1, $results->items());
        self::assertSame(3, $results->total());
    }

    /**
     * Test that a model whose table lacks its key column is refused cursor
     * pagination, which needs a unique order to seek by.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testModelWithoutAKeyColumnIsRefusedCursorPagination(): void
    {
        ApiQuery::parse(Request::create('/test', HttpMethod::GET->getVerb(), ['pagination' => 'cursor']));

        try {
            app(ImportRowRepository::class)->paginate();
            self::fail('Expected cursor pagination to be refused.');
        } catch (ValidationException $exception) {
            self::assertSame(['pagination' => ['This collection has no key to page by cursor; use offset pagination.']], $exception->errors());
        }
    }

    /**
     * Scope the repository to one row per status with its count.
     *
     * @return \Tests\Fixtures\Repositories\UserRepository
     */
    private function groupedByStatus(): UserRepository
    {
        return $this->repository->addScope(static function (Builder $query): void {
            $query->getQuery()->select('status')->selectRaw('count(*) as total')->groupBy('status');
        });
    }

    /**
     * Read a grouped row as its status and count.
     *
     * @param  mixed  $user
     * @return array{string, int}
     */
    private function statusCount(mixed $user): array
    {
        self::assertInstanceOf(User::class, $user);

        $status = $user->getAttribute('status');
        $total  = $user->getAttribute('total');

        self::assertInstanceOf(UserStatus::class, $status);
        self::assertIsNumeric($total);

        return [$status->value, (int) $total];
    }

    /**
     * Seed the database with test users.
     *
     * @return void
     */
    private function seedUsers(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'inactive']);
    }
}
