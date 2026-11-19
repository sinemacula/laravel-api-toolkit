<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\PagedUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Shared suite asking the engine how it would run the reads a page issues.
 *
 * Each statement is taken from a real request and explained with its bindings,
 * so the appended key, the cursor's seek and the per-parent window are judged
 * as emitted. Rows are committed so the planner's statistics can see them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class EngineQueryPlanTestCase extends TestCase
{
    /** @var string The ordinary index the shared users table carries on name */
    protected const string NAME_INDEX = 'users_name_index';

    /** @var string A composite index over the ordered column and the key */
    protected const string NAME_KEY_INDEX = 'users_name_id_index';

    /** @var int Enough users that reading them all costs more than a page */
    private const int USERS = 3000;

    /** @var int The posts each user owns, more than the embedded limit */
    private const int POSTS_PER_USER = 6;

    /**
     * Register the paged route and seed the rows, skipping other engines.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== $this->engine()) {
            static::markTestSkipped(sprintf('The %s query plan suite runs against its own engine only.', $this->engine()));
        }

        Config::set(ResourceDiscovery::RESOURCES_KEY, [PagedUserResource::class, PostResource::class]);

        Route::middleware(ParseApiQuery::class)->get('/users', fn (UserRepository $repository): ApiResourceCollection => new ApiResourceCollection(
            $repository->usingResource(PagedUserResource::class)->withApiCriteria()->paginate(),
            PagedUserResource::class,
        ));

        $this->seedUsersAndPosts();
        $this->refreshStatistics();
    }

    /**
     * Remove the committed rows before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === $this->engine()) {
            DB::table('posts')->delete();
            DB::table('users')->delete();
        }

        parent::tearDown();
    }

    /**
     * Leave the seeded rows committed.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Test that a deep ascending offset page reads the name index in order.
     *
     * @return void
     */
    public function testADeepAscendingOffsetPageReadsTheNameIndexInOrder(): void
    {
        $this->assertOrderedFrom(self::NAME_INDEX, $this->pageRead(['order' => 'name', 'limit' => 20, 'page' => 50]));
    }

    /**
     * Test that a deep descending offset page reads the name index in order.
     *
     * The appended key follows the last direction, so one backward scan serves
     * the whole order.
     *
     * @return void
     */
    public function testADeepDescendingOffsetPageReadsTheNameIndexInOrder(): void
    {
        $this->assertOrderedFrom(self::NAME_INDEX, $this->pageRead(['order' => 'name:desc', 'limit' => 20, 'page' => 50]));
    }

    /**
     * Test that an index over the name and the key serves the whole order
     * without sorting.
     *
     * @return void
     */
    public function testAnIndexOverTheNameAndTheKeyServesTheWholeOrder(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->index(['name', 'id'], self::NAME_KEY_INDEX);
        });

        $this->assertOrderedWithoutSortingFrom(self::NAME_KEY_INDEX, $this->pageRead(['order' => 'name', 'limit' => 20, 'page' => 50]));
    }

    /**
     * Test that a cursor page seeks into the name index from its boundary.
     *
     * @return void
     */
    public function testACursorPageSeeksIntoTheNameIndex(): void
    {
        $link = $this->getJson('/users?' . http_build_query(['order' => 'name', 'limit' => 20, 'pagination' => 'cursor']))->assertOk()->json('links.next');

        static::assertIsString($link);

        $next = parse_url($link, PHP_URL_QUERY);

        static::assertIsString($next);

        $this->assertSeeks(self::NAME_INDEX, $this->read('/users?' . $next, $this->orderByName()));
    }

    /**
     * Test that embedded posts bounded per user are read through the index on
     * their owner.
     *
     * @return void
     */
    public function testBoundedEmbeddedPostsAreReadThroughTheirOwnersIndex(): void
    {
        $query = ['order' => 'name', 'limit' => 20, 'fields' => [PagedUserResource::RESOURCE_TYPE => 'name,posts']];

        $this->assertReadThroughOwnerIndex($this->read('/users?' . http_build_query($query), 'laravel_row'));
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    abstract protected function engine(): string;

    /**
     * Assert that the engine reads the statement's rows in order from the
     * index, sorting at most the rows sharing one indexed value.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    abstract protected function assertOrderedFrom(string $index, array $statement): void;

    /**
     * Assert that the engine reads the statement's rows in order from the index
     * without sorting any of them.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    abstract protected function assertOrderedWithoutSortingFrom(string $index, array $statement): void;

    /**
     * Assert that the engine starts reading the index at the cursor's boundary.
     *
     * @param  string  $index
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    abstract protected function assertSeeks(string $index, array $statement): void;

    /**
     * Assert that the engine finds the bounded posts through an index leading
     * with their owner.
     *
     * @param  array{query: string, bindings: array<int, mixed>}  $statement
     * @return void
     */
    abstract protected function assertReadThroughOwnerIndex(array $statement): void;

    /**
     * Bring the planner's statistics up to date with the seeded rows.
     *
     * @return void
     */
    protected function refreshStatistics(): void {}

    /**
     * Return the statement reading the page's users for the query.
     *
     * @param  array<string, int|string>  $query
     * @return array{query: string, bindings: array<int, mixed>}
     */
    private function pageRead(array $query): array
    {
        return $this->read('/users?' . http_build_query($query), $this->orderByName());
    }

    /**
     * Return the order clause a page ordered by name opens with.
     *
     * @return string
     */
    private function orderByName(): string
    {
        return 'order by ' . DB::connection()->getQueryGrammar()->wrap('users.name');
    }

    /**
     * Request the URI and return the first statement it ran containing the
     * given fragment.
     *
     * @param  string  $uri
     * @param  string  $fragment
     * @return array{query: string, bindings: array<int, mixed>}
     */
    private function read(string $uri, string $fragment): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->getJson($uri)->assertOk();
        } finally {
            DB::disableQueryLog();
        }

        foreach (DB::getQueryLog() as $entry) {

            if (str_contains($entry['query'], $fragment)) {
                return ['query' => $entry['query'], 'bindings' => array_values($entry['bindings'])];
            }
        }

        static::fail(sprintf('No statement for %s contained "%s".', $uri, $fragment));
    }

    /**
     * Seed the users, each owning more posts than the embedded limit.
     *
     * Names are a permutation of the keys, so neither order follows the other.
     *
     * @return void
     */
    private function seedUsersAndPosts(): void
    {
        $users = [];

        for ($i = 1; $i <= self::USERS; $i++) {
            $users[] = ['name' => sprintf('User %04d', ($i * 7919) % self::USERS), 'email' => sprintf('user%d@example.com', $i), 'status' => 'active'];
        }

        foreach (array_chunk($users, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $posts = [];

        foreach (DB::table('users')->pluck('id') as $id) {
            for ($n = 1; $n <= self::POSTS_PER_USER; $n++) {
                $posts[] = ['user_id' => $id, 'title' => sprintf('Post %d', $n), 'body' => 'body', 'published' => false];
            }
        }

        foreach (array_chunk($posts, 500) as $chunk) {
            DB::table('posts')->insert($chunk);
        }
    }
}
