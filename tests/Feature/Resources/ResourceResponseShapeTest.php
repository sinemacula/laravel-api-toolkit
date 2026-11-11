<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for nested field selection and relation aggregates in a real
 * JSON body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversClass(ValueResolver::class)]
#[CoversClass(EagerLoadPlanner::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(FilterApplier::class)]
final class ResourceResponseShapeTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a repository-backed users route and seeded data.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->post('/users', static function (Request $request): UserResource {

            $user = User::create([
                'name'   => $request->string('name')->toString(),
                'email'  => $request->string('email')->toString(),
                'status' => 'active',
            ]);

            return new UserResource($user);
        });

        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);
        $alice        = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active', 'organization_id' => $organization->id]);

        Post::create(['user_id' => $alice->id, 'title' => 'First Post', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Second Post', 'body' => 'Content', 'published' => false]);
    }

    /**
     * Test that a nested sparse fieldset restricts the embedded relation to its
     * requested keys.
     *
     * @return void
     */
    public function testNestedSparseFieldsetRestrictsTheEmbeddedRelation(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'name,posts', 'posts' => 'title'],
        ]));

        $response->assertOk();

        /** @var array<int, array<string, mixed>> $posts */
        $posts = $response->json('data.0.posts');

        self::assertNotEmpty($posts);
        self::assertArrayHasKey('title', $posts[0]);
        self::assertArrayHasKey('_type', $posts[0]);
        self::assertArrayNotHasKey('body', $posts[0]);
        self::assertArrayNotHasKey('published', $posts[0]);
    }

    /**
     * Test that a sparse fieldset shapes the resource a write returns.
     *
     * @return void
     */
    public function testSparseFieldsetShapesTheResourceAWriteReturns(): void
    {
        $response = $this->postJson(
            '/users?' . http_build_query(['fields' => ['users' => 'name']]),
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        );

        $response->assertCreated();

        /** @var array<string, mixed> $user */
        $user = $response->json('data');

        self::assertSame('Bob', $user['name']);
        self::assertArrayNotHasKey('email', $user);
    }

    /**
     * Test that count, sum, and average aggregates surface under an item.
     *
     * @return void
     */
    public function testAggregatesSurfaceInThePayload(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields'   => ['users' => 'name,counts,sums,averages'],
            'counts'   => ['users' => 'posts'],
            'sums'     => ['users' => ['posts' => 'id']],
            'averages' => ['users' => ['posts' => 'id']],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.counts.posts', 2);

        /** @var array<string, mixed> $item */
        $item = $response->json('data.0');

        self::assertArrayHasKey('posts_id', (array) $item['sums']);
        self::assertArrayHasKey('posts_id', (array) $item['averages']);
    }

    /**
     * Test that undeclared and non-existent aggregate relations are omitted
     * without a database error.
     *
     * @return void
     */
    public function testUndeclaredAggregateRelationsAreSilentlyOmitted(): void
    {
        // The request asks for more aggregates than the shipped cap allows
        Config::set('api-toolkit.query_cost.max_aggregates', 0);

        $response = $this->getJson('/users?' . http_build_query([
            'fields'   => ['users' => 'name,counts,sums,averages'],
            'counts'   => ['users' => 'posts,organization,comments'],
            'sums'     => ['users' => ['posts' => 'id', 'organization' => 'id', 'comments' => 'id']],
            'averages' => ['users' => ['posts' => 'id', 'comments' => 'id']],
        ]));

        $response->assertOk();

        // Undeclared and non-existent relations are dropped, not queried
        $response->assertJsonPath('data.0.counts.posts', 2);

        /** @var array<string, mixed> $item */
        $item = $response->json('data.0');

        self::assertArrayNotHasKey('organization', (array) $item['counts']);
        self::assertArrayNotHasKey('comments', (array) $item['counts']);
        self::assertSame(['posts'], array_keys((array) $item['counts']));
        self::assertSame(['posts_id'], array_keys((array) $item['sums']));
        self::assertSame(['posts_id'], array_keys((array) $item['averages']));
    }

    /**
     * Test that default aggregates surface without selection while non-default
     * ones stay omitted.
     *
     * @return void
     */
    public function testDefaultAggregatesSurfaceWhileNonDefaultsAreOmitted(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'name,counts,sums,averages'],
        ]));

        $response->assertOk();

        $response->assertJsonPath('data.0.counts.posts', 2);

        /** @var array<string, mixed> $item */
        $item = $response->json('data.0');

        self::assertArrayHasKey('posts_id', (array) $item['sums']);

        // The average is declared but not default
        self::assertArrayNotHasKey('averages', $item);
    }

    /**
     * Test that the sum and average aggregate values are correct in the
     * rendered payload.
     *
     * @return void
     */
    public function testAggregateValuesAreNumericallyCorrect(): void
    {
        /** @var array<int, int> $postIds */
        $postIds     = Post::all()->pluck('id')->all();
        $expectedSum = array_sum($postIds);
        $expectedAvg = $expectedSum / count($postIds);

        $response = $this->getJson('/users?' . http_build_query([
            'fields'   => ['users' => 'name,sums,averages'],
            'sums'     => ['users' => ['posts' => 'id']],
            'averages' => ['users' => ['posts' => 'id']],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.sums.posts_id', $expectedSum);
        $response->assertJsonPath('data.0.averages.posts_id', $expectedAvg);
    }

    /**
     * Test that aggregates reflect only the rows belonging to a filtered user.
     *
     * @return void
     */
    public function testAggregatesRespectAnAppliedFilter(): void
    {
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);

        Post::create(['user_id' => $bob->id, 'title' => 'Bob One', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Two', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Three', 'body' => 'Content', 'published' => false]);

        $filters = json_encode(['name' => 'Bob']);

        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'name,counts'],
            'counts' => ['users' => 'posts'],
        ]) . '&filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 1);

        // Bob's count excludes Alice's two posts
        $response->assertJsonPath('data.0.name', 'Bob');
        $response->assertJsonPath('data.0.counts.posts', 3);
    }
}
