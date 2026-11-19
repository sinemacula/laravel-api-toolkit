<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Concerns\QuerySelectionValidator;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\LogRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\LogResource;
use Tests\Fixtures\Resources\PostResource;
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
#[CoversClass(QuerySelectionValidator::class)]
final class ResourceResponseShapeTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /**
     * Set up each test with a repository-backed users route and seeded data.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [LogResource::class, UserResource::class, PostResource::class]);

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
     * Test that to-one relations with no related row render as present null
     * keys, while populated ones render their values.
     *
     * @return void
     */
    public function testAbsentToOneRelationsRenderAsNull(): void
    {
        $alice = User::query()->where('name', 'Alice')->firstOrFail();

        Profile::create(['user_id' => $alice->id, 'bio' => 'Writes first posts']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);

        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'name,organization,profile_bio'],
        ]));

        $response->assertOk();

        /** @var array<string, array<string, mixed>> $users */
        $users = array_column((array) $response->json('data'), null, 'name');

        self::assertSame('Acme Corp', $users['Alice']['organization']['name']);
        self::assertSame('Writes first posts', $users['Alice']['profile_bio']);
        self::assertArrayHasKey('organization', $users['Bob']);
        self::assertArrayHasKey('profile_bio', $users['Bob']);
        self::assertNull($users['Bob']['organization']);
        self::assertNull($users['Bob']['profile_bio']);
    }

    /**
     * Test that a timestamp field over a column the model does not cast renders
     * the stored date-time rather than null.
     *
     * @return void
     */
    public function testATimestampOverAnUncastColumnRendersTheStoredDateTime(): void
    {
        Route::middleware(ParseApiQuery::class)->get('/logs', static function (LogRepository $repository): ApiResourceCollection {

            $logs = $repository->usingResource(LogResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($logs, LogResource::class);
        });

        Log::create(['level' => 'info', 'message' => 'Booted', 'created_at' => '2026-07-15 09:30:00.123456']);

        self::assertIsString(Log::query()->firstOrFail()->getAttribute('created_at'));

        $this->getJson('/logs?fields[logs]=created_at')
            ->assertOk()
            ->assertJsonPath('data.0.created_at', '2026-07-15T09:30:00+00:00');
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
     * Test that a write selecting an unknown field is rejected before the
     * controller runs, so nothing is persisted.
     *
     * @return void
     */
    public function testWriteSelectingAnUnknownFieldIsRejectedBeforeItPersists(): void
    {
        $response = $this->postJson(
            '/users?' . http_build_query(['fields' => ['users' => 'name,emial']]),
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        );

        $response->assertUnprocessable();

        self::assertSame(['fields.users' => ['The "emial" field is not declared on the "users" resource type.']], $response->json('error.meta'));
        self::assertSame(0, User::query()->where('email', 'bob@example.com')->count());
    }

    /**
     * Test that a write body carrying a fields attribute is persisted, since
     * the body is never read as a query parameter.
     *
     * @return void
     */
    public function testWriteBodyCarryingAFieldsAttributeSucceeds(): void
    {
        $response = $this->postJson('/users', [
            'name'   => 'Bob',
            'email'  => 'bob@example.com',
            'fields' => [['label' => 'Team', 'value' => 'Platform']],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Bob');
    }

    /**
     * Test that count, sum, and average aggregates surface under one aggregates
     * object on an item.
     *
     * @return void
     */
    public function testAggregatesSurfaceInThePayload(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields'     => ['users' => 'name,aggregates'],
            'aggregates' => ['users' => 'posts_count,posts_sum_id,posts_avg_id'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.aggregates.posts_count', 2);

        /** @var array<string, mixed> $item */
        $item = $response->json('data.0');

        self::assertSame(['posts_count', 'posts_sum_id', 'posts_avg_id'], array_keys((array) $item['aggregates']));
        self::assertArrayNotHasKey('counts', $item);
    }

    /**
     * Test that undeclared aggregate keys are rejected together, naming each,
     * before any query runs and before the aggregate cap is charged.
     *
     * @return void
     */
    public function testUndeclaredAggregatesAreRejectedBeforeAnyQuery(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 1);

        $statements = $this->recordStatements(function (): void {

            $response = $this->getJson('/users?' . http_build_query([
                'fields'     => ['users' => 'name,aggregates'],
                'aggregates' => ['users' => 'posts_count,organization_count,posts'],
            ]));

            $response->assertUnprocessable();

            self::assertSame(['aggregates.users' => [
                'The "organization_count" aggregate is not declared on the "users" resource type.',
                'The "posts" aggregate is not declared on the "users" resource type.',
            ]], $response->json('error.meta'));
        });

        self::assertStatementCount(0, $statements);
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
            'fields' => ['users' => 'name,aggregates'],
        ]));

        $response->assertOk();

        $response->assertJsonPath('data.0.aggregates.posts_count', 2);

        /** @var array<string, mixed> $item */
        $item = $response->json('data.0');

        // The average is declared but not default
        self::assertSame(['posts_count', 'posts_sum_id'], array_keys((array) $item['aggregates']));
    }

    /**
     * Test that requesting one aggregate drops every default of the type.
     *
     * @return void
     */
    public function testARequestedAggregateDropsTheDefaults(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields'     => ['users' => 'name,aggregates'],
            'aggregates' => ['users' => 'posts_count'],
        ]));

        $response->assertOk();

        /** @var array<string, mixed> $item */
        $item = $response->json('data.0');

        self::assertSame(['posts_count' => 2], $item['aggregates']);
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
            'fields'     => ['users' => 'name,aggregates'],
            'aggregates' => ['users' => 'posts_sum_id,posts_avg_id'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.aggregates.posts_sum_id', $expectedSum);
        $response->assertJsonPath('data.0.aggregates.posts_avg_id', $expectedAvg);
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
            'fields'     => ['users' => 'name,aggregates'],
            'aggregates' => ['users' => 'posts_count'],
        ]) . '&filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 1);

        // Bob's count excludes Alice's two posts
        $response->assertJsonPath('data.0.name', 'Bob');
        $response->assertJsonPath('data.0.aggregates.posts_count', 3);
    }
}
