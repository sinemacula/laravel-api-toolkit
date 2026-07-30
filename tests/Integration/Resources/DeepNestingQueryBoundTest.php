<?php

declare(strict_types = 1);

namespace Tests\Integration\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Query-bound guard for a three-level nested field selection.
 *
 * Drives the eager-load planner across users -> posts -> tags, then serialises
 * the collection under a query log. The count must be a small constant (one
 * query per level, batched) and identical for a small and a large row count:
 * were the third level loaded per row, the count would scale with the rows. The
 * tags are asserted to have hydrated so a constant count cannot mean the
 * deepest level simply never loaded.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(EagerLoadPlanner::class)]
final class DeepNestingQueryBoundTest extends TestCase
{
    /** @var string The route URI used to exercise the test endpoint. */
    private const string TEST_URL = '/test';

    /** @var int The query bound: one query per level for users, posts, tags. */
    private const int EXPECTED_QUERIES = 3;

    /** @var array<int, int> The tag ids attached to every seeded post. */
    private array $tagIds = [];

    /**
     * Set up each test with the blocklist posture and a tag pool.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // This test measures eager-load query shape, not the query posture; pin
        // the blocklist posture so the empty-surface criteria uses the legacy
        // isSearchable contract.
        Config::set('api-toolkit.repositories.query_posture', QuerySurface::POSTURE_BLOCKLIST);

        // These assertions measure query shape and call counts; pin column
        // narrowing off so the on-by-default narrowing metadata pass cannot
        // skew them (narrowing behaviour has its own dedicated coverage).
        Config::set('api-toolkit.resources.narrow_columns', false);

        $this->tagIds = [
            Tag::create(['name' => 'php'])->id,
            Tag::create(['name' => 'laravel'])->id,
        ];

        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $this->seedUsers(5, $organization->id);
    }

    /**
     * The three-level fetch-and-serialise count is constant regardless of row
     * count, proving the tag level is batched rather than loaded per row.
     *
     * @return void
     */
    public function testDeepNestingQueryCountIsConstantRegardlessOfRowCount(): void
    {
        $this->parseNestedQuery();

        $small = $this->fetchAndSerialise();

        /** @var \Tests\Fixtures\Models\Organization $organization */
        $organization = Organization::query()->firstOrFail();

        // Grow the result set an order of magnitude; a per-row load of the
        // deepest level would scale the query count with these extra rows.
        $this->seedUsers(45, $organization->id, 5);

        $large = $this->fetchAndSerialise();

        self::assertSame($small['queries'], $large['queries']);
        self::assertSame(self::EXPECTED_QUERIES, $large['queries']);

        // Prove every level hydrated, so the constant count reflects batched
        // eager loading rather than the deepest relation never loading.
        self::assertNotEmpty($large['first']['posts']);

        $firstPost = $large['first']['posts'][0];

        self::assertArrayHasKey('tags', $firstPost);
        self::assertNotEmpty($firstPost['tags']);
        self::assertArrayHasKey('name', $firstPost['tags'][0]);

        // The user resource declares default count and sum metrics, but this
        // request asks for neither, so no metric block may leak into the
        // response - the schema gate must not run a payload the client did not
        // request.
        self::assertArrayNotHasKey('counts', $large['first']);
        self::assertArrayNotHasKey('sums', $large['first']);
    }

    /**
     * Fetch the seeded users through the criteria chain and serialise them
     * under a query log, returning the query count and the decoded record.
     *
     * @return array{queries: int, first: array<string, mixed>}
     */
    private function fetchAndSerialise(): array
    {
        DB::enableQueryLog();

        $users = $this->applyUserCriteria()->get();
        $data  = UserResource::collection($users)->resolve(Request::create(self::TEST_URL));

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        $decoded = json_decode((string) json_encode($data), true);

        /** @var array<int, array<string, mixed>> $decoded */
        return [
            'queries' => $queries,
            'first'   => $decoded[0] ?? [],
        ];
    }

    /**
     * Parse a user request declaring the nested users -> posts -> tags field
     * selection so the planner recurses all three levels.
     *
     * @return void
     */
    private function parseNestedQuery(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => [
                'users' => 'name,posts',
                'posts' => 'title,tags',
                'tags'  => 'name',
            ],
        ]);

        ApiQuery::parse($request);
    }

    /**
     * Apply the criteria chain to a user query bound to the user resource.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function applyUserCriteria(): Builder
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        /** @var \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User> */
        return $criteria->usingResource(UserResource::class)->apply(new User);
    }

    /**
     * Seed the given number of users, each with two posts, each post carrying
     * the shared tag pool.
     *
     * @param  int  $count
     * @param  int  $organizationId
     * @param  int  $offset
     * @return void
     */
    private function seedUsers(int $count, int $organizationId, int $offset = 0): void
    {
        for ($index = 1; $index <= $count; $index++) {

            $number = $index + $offset;

            $user = User::create([
                'name'            => 'User ' . $number,
                'email'           => 'user' . $number . '@example.com',
                'status'          => 'active',
                'organization_id' => $organizationId,
            ]);

            $postA = Post::create(['user_id' => $user->id, 'title' => 'Post ' . $number . 'a', 'body' => 'Content', 'published' => true]);
            $postB = Post::create(['user_id' => $user->id, 'title' => 'Post ' . $number . 'b', 'body' => 'Content', 'published' => false]);

            $postA->tags()->attach($this->tagIds);
            $postB->tags()->attach($this->tagIds);
        }
    }
}
