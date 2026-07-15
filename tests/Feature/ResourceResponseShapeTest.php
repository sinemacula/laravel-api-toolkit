<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
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

        Route::middleware(ParseApiQuery::class)->get('/api/users', function (UserRepository $repository): ApiResourceCollection {

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
        $response = $this->getJson('/api/users?' . http_build_query([
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
        $response = $this->getJson('/api/users?' . http_build_query([
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
}
