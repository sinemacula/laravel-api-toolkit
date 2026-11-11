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
     * Set up each test with a tag pool and a seeded organization.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Narrowing adds a metadata pass that would skew the query counts
        Config::set('api-toolkit.resources.narrow_columns', false);

        $this->tagIds = [
            Tag::create(['name' => 'php'])->id,
            Tag::create(['name' => 'laravel'])->id,
        ];

        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $this->seedUsers(5, $organization->id);
    }

    /**
     * Test that the three-level query count is constant across row counts.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeepNestingQueryCountIsConstantRegardlessOfRowCount(): void
    {
        $this->parseNestedQuery();

        $small = $this->fetchAndSerialise();

        /** @var \Tests\Fixtures\Models\Organization $organization */
        $organization = Organization::query()->firstOrFail();

        // A per-row load of the deepest level would scale with these rows
        $this->seedUsers(45, $organization->id, 5);

        $large = $this->fetchAndSerialise();

        self::assertSame($small['queries'], $large['queries']);
        self::assertSame(self::EXPECTED_QUERIES, $large['queries']);

        // Every level hydrated, so the constant count is not an empty load
        self::assertNotEmpty($large['first']['posts']);

        $firstPost = $large['first']['posts'][0];

        self::assertIsArray($firstPost);
        self::assertArrayHasKey('tags', $firstPost);
        self::assertNotEmpty($firstPost['tags']);
        self::assertArrayHasKey('name', $firstPost['tags'][0]);

        // Default metrics the request did not ask for must not leak
        self::assertArrayNotHasKey('counts', $large['first']);
        self::assertArrayNotHasKey('sums', $large['first']);
    }

    /**
     * Fetch and serialise the users under a query log.
     *
     * @return array{queries: int, first: array<string, mixed>}
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     * Parse a request selecting users -> posts -> tags so the planner recurses.
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     * Seed users, each with two posts carrying the shared tag pool.
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
