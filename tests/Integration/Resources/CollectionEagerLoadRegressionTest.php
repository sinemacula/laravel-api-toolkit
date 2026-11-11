<?php

declare(strict_types = 1);

namespace Tests\Integration\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Regression guard for N+1 queries when serialising a collection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(EagerLoadApplier::class)]
final class CollectionEagerLoadRegressionTest extends TestCase
{
    /** @var string The route URI used to exercise the test endpoint. */
    private const string TEST_URL = '/test';

    /** @var int The upper bound on queries for the whole fetch-and-serialise pass. */
    private const int EXPECTED_QUERIES = 3;

    /**
     * Set up each test with a seeded organization.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Narrowing adds a metadata pass that would skew the query counts
        Config::set('api-toolkit.resources.narrow_columns', false);

        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $this->seedUsers(5, $organization->id);
    }

    /**
     * Test that the query count is the same for a small and a large collection.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testCollectionQueryCountIsConstantRegardlessOfRowCount(): void
    {
        $this->parseUserQuery('name,organization,posts');

        $small = $this->fetchAndSerialise();

        /** @var \Tests\Fixtures\Models\Organization $organization */
        $organization = Organization::query()->firstOrFail();

        // An N+1 regression would scale the query count with these extra rows
        $this->seedUsers(45, $organization->id, 5);

        $large = $this->fetchAndSerialise();

        self::assertSame($small['queries'], $large['queries']);
        self::assertSame(self::EXPECTED_QUERIES, $large['queries']);

        // The relations hydrated, so the constant count is not an empty load
        self::assertSame('Acme Corp', $large['first']['organization']['name']);
        self::assertNotEmpty($large['first']['posts']);
        self::assertIsArray($large['first']['posts'][0]);
        self::assertArrayHasKey('title', $large['first']['posts'][0]);
    }

    /**
     * Test that aggregates fold into the base query regardless of row count.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAggregatesFoldIntoTheBaseQueryRegardlessOfRowCount(): void
    {
        $this->parseUserQuery('name,counts,sums,averages', [
            'counts'   => ['users' => 'posts'],
            'sums'     => ['users' => ['posts' => 'id']],
            'averages' => ['users' => ['posts' => 'id']],
        ]);

        $small = $this->fetchAndSerialise();

        /** @var \Tests\Fixtures\Models\Organization $organization */
        $organization = Organization::query()->firstOrFail();

        $this->seedUsers(45, $organization->id, 5);

        $large = $this->fetchAndSerialise();

        self::assertSame($small['queries'], $large['queries']);
        self::assertSame(1, $large['queries']);

        // The aggregates resolved, so the constant count is not an empty load
        self::assertSame(2, $large['first']['counts']['posts']);
        self::assertIsArray($large['first']['sums']);
        self::assertArrayHasKey('posts_id', $large['first']['sums']);
        self::assertIsArray($large['first']['averages']);
        self::assertArrayHasKey('posts_id', $large['first']['averages']);
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
     * Parse a user request for the given field set and optional extra params.
     *
     * @param  string  $fields
     * @param  array<string, mixed>  $extra
     * @return void
     */
    private function parseUserQuery(string $fields, array $extra = []): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['users' => $fields],
            ...$extra,
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
     * Seed the given number of users in the organization, each with two posts.
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

            Post::create(['user_id' => $user->id, 'title' => 'Post ' . $number . 'a', 'body' => 'Content', 'published' => true]);
            Post::create(['user_id' => $user->id, 'title' => 'Post ' . $number . 'b', 'body' => 'Content', 'published' => false]);
        }
    }
}
