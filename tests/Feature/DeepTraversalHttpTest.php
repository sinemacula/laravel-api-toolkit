<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
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
use Tests\Fixtures\Resources\DeepTraversalPostResource;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test for a multi-hop relation chain applied over HTTP.
 *
 * When the related resource declares an onward traversable relation the chain
 * users -> posts -> user is permitted at every hop and compiles to nested
 * whereHas constraints. A real request proves the per-hop gating and the
 * nested existence constraint survive the HTTP layer: only the user owning a
 * post whose onward user matches the nested predicate is returned.
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
     * Set up each test with a users route, the deep-traversal Post resource
     * map, and seeded users and posts.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [
            Post::class => DeepTraversalPostResource::class,
        ]);

        Route::middleware(ParseApiQuery::class)->get('/api/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        Post::create(['user_id' => $alice->id, 'title' => 'Alice Post', 'body' => 'Content']);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Post', 'body' => 'Content']);
    }

    /**
     * Test that a users -> posts -> user chain narrows the envelope to the
     * single matching user.
     *
     * @return void
     */
    public function testMultiHopRelationChainNarrowsTheEnvelope(): void
    {
        $filters = json_encode(['posts' => ['nested' => ['user' => ['name' => 'Alice']]]]);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
        $response->assertJsonPath('meta.total', 1);
    }
}
