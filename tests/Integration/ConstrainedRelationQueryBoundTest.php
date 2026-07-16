<?php

declare(strict_types = 1);

namespace Tests\Integration;

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
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ConstrainedUserResource;
use Tests\TestCase;

/**
 * Query-bound guard for a constrained eager-loaded relation.
 *
 * The user resource declares a posts relation constrained to published posts.
 * Serialising a collection under a query log must load that relation through a
 * single constrained sub-query for the whole set - constant, not one per row -
 * and the constraint must actually apply so only published posts surface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(EagerLoadPlanner::class)]
final class ConstrainedRelationQueryBoundTest extends TestCase
{
    /** @var string The route URI used to exercise the test endpoint. */
    private const string TEST_URL = '/test';

    /** @var int The query bound: one query for users, one for constrained posts. */
    private const int EXPECTED_QUERIES = 2;

    /** @var int The number of published posts seeded per user. */
    private const int PUBLISHED_PER_USER = 2;

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
     * The constrained relation loads in one extra query for the whole
     * collection regardless of row count, and only published posts surface.
     *
     * @return void
     */
    public function testConstrainedRelationLoadsOnceAndAppliesTheConstraint(): void
    {
        $this->parseConstrainedQuery();

        $small = $this->fetchAndSerialise();

        /** @var \Tests\Fixtures\Models\Organization $organization */
        $organization = Organization::query()->firstOrFail();

        // Grow the result set an order of magnitude; a per-row constrained load
        // would scale the query count with these extra rows.
        $this->seedUsers(45, $organization->id, 5);

        $large = $this->fetchAndSerialise();

        self::assertSame($small['queries'], $large['queries']);
        self::assertSame(self::EXPECTED_QUERIES, $large['queries']);

        // Prove the constraint applied: only the published posts hydrated, so
        // the constant count reflects a single constrained sub-query rather
        // than an unconstrained or empty load.
        /** @var list<array<string, mixed>> $posts */
        $posts = $large['first']['posts'];

        self::assertCount(self::PUBLISHED_PER_USER, $posts);

        foreach ($posts as $post) {
            self::assertTrue($post['published'] === true);
            self::assertIsString($post['title']);
            self::assertStringStartsWith('Published', $post['title']);
        }
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
        $data  = ConstrainedUserResource::collection($users)->resolve(Request::create(self::TEST_URL));

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
     * Parse a request selecting the constrained posts relation and enough post
     * fields to observe the applied constraint.
     *
     * @return void
     */
    private function parseConstrainedQuery(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => [
                'constrained_users' => 'name,posts',
                'posts'             => 'id,title,published',
            ],
        ]);

        ApiQuery::parse($request);
    }

    /**
     * Apply the criteria chain to a user query bound to the constrained
     * resource.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function applyUserCriteria(): Builder
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        /** @var \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User> */
        return $criteria->usingResource(ConstrainedUserResource::class)->apply(new User);
    }

    /**
     * Seed users each with a mix of published and unpublished posts.
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

            Post::create(['user_id' => $user->id, 'title' => 'Published ' . $number . 'a', 'body' => 'Content', 'published' => true]);
            Post::create(['user_id' => $user->id, 'title' => 'Published ' . $number . 'b', 'body' => 'Content', 'published' => true]);
            Post::create(['user_id' => $user->id, 'title' => 'Draft ' . $number, 'body' => 'Content', 'published' => false]);
        }
    }
}
