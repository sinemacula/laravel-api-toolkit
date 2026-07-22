<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

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
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Feature test for filtering on a traversable relation column over HTTP.
 *
 * Under the allowlist posture a filter keyed on a declared-traversable relation
 * whose related resource declares the target column filterable compiles to a
 * whereHas constraint. A real request proves the nested key narrows the
 * envelope to only the users owning a matching related row, with the meta total
 * reduced accordingly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(ApiResourceCollection::class)]
final class NestedRelationFilterHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a users route, the Post resource map, and seeded
     * users and posts.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [
            Post::class => PostResource::class,
        ]);

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);

        Post::create(['user_id' => $alice->id, 'title' => 'Alice Post', 'body' => 'Content']);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Post', 'body' => 'Content']);
    }

    /**
     * Test that a filter on a traversable relation column narrows the envelope
     * to the matching owner.
     *
     * @return void
     */
    public function testRelationColumnFilterNarrowsTheEnvelope(): void
    {
        $filters = json_encode(['posts' => ['title' => ['$like' => 'Alice']]]);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
        $response->assertJsonPath('meta.total', 1);
    }
}
