<?php

declare(strict_types = 1);

namespace Tests\Feature;

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
 * JSON response body.
 *
 * Drives the full request lifecycle so the serialization and embedding step is
 * proven on the wire: a nested sparse fieldset restricts an embedded relation
 * to its requested keys, and the count/sum/average aggregates surface under an
 * item.
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
     * Test that undeclared and non-existent aggregate relations are silently
     * omitted without raising a database error.
     *
     * @return void
     */
    public function testUndeclaredAggregateRelationsAreSilentlyOmitted(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields'   => ['users' => 'name,counts,sums,averages'],
            'counts'   => ['users' => 'posts,organization,comments'],
            'sums'     => ['users' => ['posts' => 'id', 'organization' => 'id', 'comments' => 'id']],
            'averages' => ['users' => ['posts' => 'id', 'comments' => 'id']],
        ]));

        $response->assertOk();

        // Only the declared aggregate surfaces; the undeclared relation
        // (organization) and the non-existent relation (comments) are dropped
        // rather than compiled into a broken query.
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
     * Test that default-flagged aggregates surface without explicit selection
     * while non-default aggregates stay omitted.
     *
     * @return void
     */
    public function testDefaultAggregatesSurfaceWhileNonDefaultsAreOmitted(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'name,counts,sums,averages'],
        ]));

        $response->assertOk();

        // The default count and sum surface with no selection parameters.
        $response->assertJsonPath('data.0.counts.posts', 2);

        /** @var array<string, mixed> $item */
        $item = $response->json('data.0');

        self::assertArrayHasKey('posts_id', (array) $item['sums']);

        // The average is declared but not default, so the whole bucket is
        // absent when nothing requests it.
        self::assertArrayNotHasKey('averages', $item);
    }

    /**
     * Test that the sum and average aggregate values are numerically correct in
     * the rendered payload.
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
        // The blocklist posture lets a filter narrow on an undeclared column,
        // since UserResource declares no filterable columns of its own.
        Config::set('api-toolkit.repositories.query_posture', 'blocklist');

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

        // The single returned row is Bob, and its count reflects only Bob's
        // three posts, not Alice's two.
        $response->assertJsonPath('data.0.name', 'Bob');
        $response->assertJsonPath('data.0.counts.posts', 3);
    }
}
