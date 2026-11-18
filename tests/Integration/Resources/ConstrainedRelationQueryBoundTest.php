<?php

declare(strict_types = 1);

namespace Tests\Integration\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ConstrainedUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Query-bound guard for a constrained eager-loaded relation.
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
    use RecordsStatements;

    /** @var string The route URI used to exercise the test endpoint. */
    private const string TEST_URL = '/test';

    /** @var int The query bound: one query for users, one for constrained posts. */
    private const int EXPECTED_QUERIES = 2;

    /** @var int The number of published posts seeded per user. */
    private const int PUBLISHED_PER_USER = 2;

    /**
     * Set up each test with a seeded organization.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [ConstrainedUserResource::class, PostResource::class]);

        // Narrowing adds a metadata pass that would skew the query counts
        Config::set('api-toolkit.resources.narrow_columns', false);

        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $this->seedUsers(5, $organization->id);
    }

    /**
     * Test that the constrained relation loads once and applies its constraint.
     *
     * @return void
     */
    public function testConstrainedRelationLoadsOnceAndAppliesTheConstraint(): void
    {
        $this->parseConstrainedQuery();

        $small = $this->fetchAndSerialise();

        /** @var \Tests\Fixtures\Models\Organization $organization */
        $organization = Organization::query()->firstOrFail();

        // A per-row constrained load would scale the count with these rows
        $this->seedUsers(45, $organization->id, 5);

        $large = $this->fetchAndSerialise();

        self::assertStatementCount(self::EXPECTED_QUERIES, $small['statements']);
        self::assertStatementCount(self::EXPECTED_QUERIES, $large['statements']);

        // Only published posts hydrated, so the constraint applied
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
     * Fetch and serialise the users, recording every statement the pass runs.
     *
     * @return array{statements: list<string>, first: array<string, mixed>}
     */
    private function fetchAndSerialise(): array
    {
        $json = '';

        $statements = $this->recordStatements(function () use (&$json): void {
            $users = $this->applyUserCriteria()->get();
            $json  = (string) json_encode(ConstrainedUserResource::collection($users)->resolve(Request::create(self::TEST_URL)));
        });

        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = json_decode($json, true);

        return [
            'statements' => $statements,
            'first'      => $decoded[0] ?? [],
        ];
    }

    /**
     * Parse a request selecting the constrained posts relation.
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
     * Apply the criteria chain to a user query on the constrained resource.
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
