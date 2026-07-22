<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
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
 * Feature tests proving a nested-relation rejection renders as a 422 envelope.
 *
 * A ValidationException raised deep inside a traversed relation - an undeclared
 * column on the related resource, or an onward traversal the related resource
 * never declared - must unwind out of the whereHas closure mid-pagination and
 * render as the toolkit 422 envelope keyed on the offending nested field, not
 * escape the kernel as a 500. Both the undeclared-column and the
 * undeclared-traversal cases are driven through a real request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(ApiExceptionHandler::class)]
#[CoversClass(ApiResourceCollection::class)]
final class NestedRelationRejectionEnvelopeTest extends TestCase
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

        Post::create(['user_id' => $alice->id, 'title' => 'Alice Post', 'body' => 'Content']);
    }

    /**
     * Test that an undeclared column on a traversed relation renders as a 422
     * envelope keyed on the nested column, not a 500.
     *
     * @return void
     */
    public function testUndeclaredNestedColumnRendersValidationEnvelope(): void
    {
        $filters = json_encode(['posts' => ['body' => 'Content']]);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
        $response->assertJsonPath('error.code', 10106);

        self::assertArrayHasKey('filters.body', (array) $response->json('error.meta'));
    }

    /**
     * Test that an undeclared onward traversal renders as a 422 envelope keyed
     * on the nested relation, not a 500.
     *
     * @return void
     */
    public function testUndeclaredNestedTraversalRendersValidationEnvelope(): void
    {
        $filters = json_encode(['posts' => ['nested' => ['user' => ['name' => 'Alice']]]]);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
        $response->assertJsonPath('error.code', 10106);

        self::assertArrayHasKey('filters.user', (array) $response->json('error.meta'));
    }
}
