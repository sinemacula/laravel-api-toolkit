<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\DeepTraversalUserResource;
use Tests\TestCase;

/**
 * Feature test for a multi-hop relation chain filter over HTTP.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(ApiResourceCollection::class)]
final class DeepTraversalHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a users route and seeded users and posts.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(DeepTraversalUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, DeepTraversalUserResource::class);
        });

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'inactive']);

        Post::create(['user_id' => $alice->id, 'title' => 'Alice Post', 'body' => 'Content']);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Post', 'body' => 'Content']);
    }

    /**
     * Test that a users -> posts -> user chain narrows the envelope to the
     * matching user.
     *
     * @return void
     */
    public function testMultiHopRelationChainNarrowsTheEnvelope(): void
    {
        $filters = json_encode(['posts' => ['user' => ['name' => 'Alice']]]);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
        $response->assertJsonPath('meta.total', 1);
    }

    /**
     * Test that the author level is governed by the resource the post declares
     * for it, which filters a column the root resource does not.
     *
     * @return void
     */
    public function testNestedLevelOfTheRootModelIsGovernedByItsDeclaredResource(): void
    {
        $filters = json_encode(['posts' => ['user' => ['status' => 'inactive']]]);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonPath('data.*.name', ['Bob']);
    }

    /**
     * Test that a column the root resource filters is refused at a nested level
     * whose declared resource does not.
     *
     * @return void
     */
    public function testRootColumnIsRefusedWhereTheNestedResourceOmitsIt(): void
    {
        $this->getJson('/users?filters=' . urlencode((string) json_encode(['created_at' => ['$ge' => '2000-01-01']])))->assertOk();

        $filters = json_encode(['posts' => ['user' => ['created_at' => ['$ge' => '2000-01-01']]]]);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertStatus(422);

        self::assertArrayHasKey('filters.created_at', (array) $response->json('error.meta'));
    }
}
