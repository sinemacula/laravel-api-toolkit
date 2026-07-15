<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Regression guard for N+1 queries when serialising a relation-bearing
 * collection.
 *
 * Drives the eager-load planner through the criteria chain, then serialises
 * the resulting collection under a query log. The query count must be constant
 * regardless of how many rows the collection holds: a regression that made a
 * nested relation resolve per-row would inflate the count in lockstep with the
 * row count. The serialised relations are asserted to prove the constant count
 * reflects eager loading rather than nothing loading at all.
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
     * Set up each test with the blocklist posture and a seeded organization.
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

        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $this->seedUsers(5, $organization->id);
    }

    /**
     * The fetch-and-serialise query count is identical for a small and a large
     * collection, and stays within the eager-load bound.
     *
     * @return void
     */
    public function testCollectionQueryCountIsConstantRegardlessOfRowCount(): void
    {
        $this->parseUserQuery('name,organization,posts');

        $small = $this->fetchAndSerialise();

        /** @var \Tests\Fixtures\Models\Organization $organization */
        $organization = Organization::query()->firstOrFail();

        // Grow the result set an order of magnitude; an N+1 regression would
        // scale the query count with these extra rows.
        $this->seedUsers(45, $organization->id, 5);

        $large = $this->fetchAndSerialise();

        self::assertSame($small['queries'], $large['queries']);
        self::assertSame(self::EXPECTED_QUERIES, $large['queries']);

        // Prove the relations actually hydrated, so the constant count reflects
        // eager loading rather than a firewall returning missing values.
        self::assertSame('Acme Corp', $large['first']['organization']['name']);
        self::assertNotEmpty($large['first']['posts']);
        self::assertArrayHasKey('title', $large['first']['posts'][0]);
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
     * Parse a user request for the given field set.
     *
     * @param  string  $fields
     * @return void
     */
    private function parseUserQuery(string $fields): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['users' => $fields],
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
