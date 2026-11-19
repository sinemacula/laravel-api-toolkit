<?php

declare(strict_types = 1);

namespace Tests\Integration\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\MeteredAuthorResource;
use Tests\Fixtures\Resources\MeteredPostResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Query-bound tests for aggregates requested on embedded resources.
 *
 * Statements are recorded until the response is encoded, which is where the
 * embedded resources resolve.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(ApiResource::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(EagerLoadPlanner::class)]
#[CoversClass(FieldResolver::class)]
final class NestedAggregateQueryBoundTest extends TestCase
{
    use RecordsStatements;

    /** @var string The route URI used to exercise the test endpoint. */
    private const string TEST_URL = '/test';

    /** @var array<int, int> The tag ids attached to every seeded post. */
    private array $tagIds = [];

    /** @var int The organization every seeded user belongs to. */
    private int $organizationId;

    /**
     * Seed a tag pool, an organization and five users with two posts each.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            MeteredAuthorResource::class,
            MeteredPostResource::class,
            OrganizationResource::class,
            PostResource::class,
            TagResource::class,
            UserResource::class,
        ]);

        // Narrowing adds a metadata pass that would skew the statement counts
        Config::set('api-toolkit.resources.narrow_columns', false);

        $this->tagIds = [
            Tag::create(['name' => 'php'])->id,
            Tag::create(['name' => 'featured-laravel'])->id,
        ];

        $this->organizationId = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp'])->id;

        $this->seedUsers(1, 5);
    }

    /**
     * Test that every embedded post, the first included, carries its requested
     * aggregate, loaded with the posts in a statement count constant in the
     * number of users.
     *
     * @return void
     */
    public function testEmbeddedAggregatesLoadForEveryItemAtAConstantStatementCount(): void
    {
        $query = [
            'fields'     => ['users' => 'name,posts', 'posts' => 'title,aggregates'],
            'aggregates' => ['posts' => 'tags_count'],
        ];

        [$small, $smallStatements] = $this->render(UserResource::class, new User, $query);

        $this->seedUsers(6, 50);

        [$large, $largeStatements] = $this->render(UserResource::class, new User, $query);

        self::assertStatementCount(2, $smallStatements);
        self::assertStatementCount(2, $largeStatements);
        self::assertCount(50, $large);

        foreach ($large as $user) {
            self::assertSame([2, 2], array_column(array_column((array) $user['posts'], 'aggregates'), 'tags_count'));
        }

        foreach ($largeStatements as $statement) {
            self::assertStringNotContainsString('where posts.id in', $this->unquote($statement));
        }

        self::assertSame($small, array_slice($large, 0, 5));
    }

    /**
     * Test that a singular embedded resource carries its requested aggregate.
     *
     * @return void
     */
    public function testSingularEmbeddedResourceCarriesItsAggregate(): void
    {
        [$users] = $this->render(UserResource::class, new User, [
            'fields'     => ['users' => 'name,organization', 'organizations' => 'name,aggregates'],
            'aggregates' => ['organizations' => 'users_count'],
        ]);

        self::assertSame(array_fill(0, 5, ['users_count' => 5]), array_column(array_column($users, 'organization'), 'aggregates'));
    }

    /**
     * Test that an embedded type selecting its aggregates without naming any
     * renders its defaults.
     *
     * @return void
     */
    public function testEmbeddedDefaultAggregatesRender(): void
    {
        [$posts] = $this->render(PostResource::class, new Post, [
            'fields' => ['posts' => 'title,user', 'users' => 'name,aggregates'],
        ]);

        foreach ($posts as $post) {

            /** @var \Tests\Fixtures\Models\User $author */
            $author = User::query()->findOrFail($post['user']['id']);

            self::assertSame(['posts_count', 'posts_sum_id'], array_keys((array) $post['user']['aggregates']));
            self::assertSame(2, $post['user']['aggregates']['posts_count']);
            self::assertEquals($author->posts()->sum('id'), $post['user']['aggregates']['posts_sum_id']);
        }
    }

    /**
     * Test that the aggregates an embedded type requests replace its defaults.
     *
     * @return void
     */
    public function testRequestedEmbeddedAggregatesReplaceTheDefaults(): void
    {
        [$posts] = $this->render(PostResource::class, new Post, [
            'fields'     => ['posts' => 'title,user', 'users' => 'name,aggregates'],
            'aggregates' => ['users' => 'posts_avg_id'],
        ]);

        self::assertSame(['posts_avg_id'], array_keys((array) $posts[0]['user']['aggregates']));
    }

    /**
     * Test that a resource embedded where the ancestor cycle stops descending
     * still carries its aggregates.
     *
     * @return void
     */
    public function testResourceTheCycleCutsCarriesItsAggregates(): void
    {
        [$users, $statements] = $this->render(UserResource::class, new User, [
            'fields' => ['users' => 'name,posts,aggregates', 'posts' => 'title,user'],
        ]);

        self::assertStatementCount(3, $statements);
        self::assertSame(2, $users[0]['aggregates']['posts_count']);
        self::assertSame(2, $users[0]['posts'][0]['user']['aggregates']['posts_count']);
        self::assertSame(2, $users[4]['posts'][1]['user']['aggregates']['posts_count']);
    }

    /**
     * Test that a constrained path selecting columns through another field
     * applies the columns, the constraint and both counts over one relation.
     *
     * @return void
     */
    public function testConstrainedPathAppliesItsColumnsAndEveryCount(): void
    {
        [$authors, $statements] = $this->render(MeteredAuthorResource::class, new User, [
            'fields'     => ['metered_authors' => 'name,posts', 'metered_posts' => 'title,aggregates'],
            'aggregates' => ['metered_posts' => 'tags_count,featured_tags_count'],
        ]);

        self::assertStatementCount(2, $statements);
        self::assertStringContainsString('select id, user_id, title, (select count(*)', $this->unquote($statements[1]));

        foreach ($authors as $author) {
            self::assertCount(1, (array) $author['posts']);
            self::assertSame(['tags_count' => 2, 'featured_tags_count' => 1], $author['posts'][0]['aggregates']);
        }
    }

    /**
     * Test that ':all' on an embedded type renders its relations and requested
     * aggregate.
     *
     * @return void
     */
    public function testAllTokenOnAnEmbeddedTypeRendersItsRelationsAndAggregates(): void
    {
        [$users] = $this->render(UserResource::class, new User, [
            'fields'     => ['users' => 'name,posts', 'posts' => ':all'],
            'aggregates' => ['posts' => 'tags_count'],
        ]);

        $post = $users[0]['posts'][0];

        self::assertCount(2, (array) $post['tags']);
        self::assertSame($users[0]['id'], $post['user']['id']);
        self::assertSame(['tags_count' => 2], $post['aggregates']);
    }

    /**
     * Test that a guarded embedded aggregate renders only for a viewer who
     * passes the guard.
     *
     * @return void
     */
    public function testGuardedEmbeddedAggregateFollowsTheViewer(): void
    {
        $query = [
            'fields'     => ['metered_authors' => 'name,posts', 'metered_posts' => 'title,aggregates'],
            'aggregates' => ['metered_posts' => 'tags_count,audited_tags_count'],
        ];

        [$anonymous] = $this->render(MeteredAuthorResource::class, new User, $query);
        [$auditor]   = $this->render(MeteredAuthorResource::class, new User, $query, ['HTTP_X_VIEWER' => 'auditor']);

        self::assertSame(['tags_count' => 2], $anonymous[0]['posts'][0]['aggregates']);
        self::assertSame(['tags_count' => 2, 'audited_tags_count' => 2], $auditor[0]['posts'][0]['aggregates']);
    }

    /**
     * Test that default aggregates the selection leaves out neither render nor
     * run a subquery.
     *
     * @return void
     */
    public function testUnselectedDefaultAggregatesRunNoSubquery(): void
    {
        [$users, $statements] = $this->render(UserResource::class, new User, ['fields' => ['users' => 'name']]);

        self::assertStatementCount(1, $statements);
        self::assertStringNotContainsString('aggregate_', $statements[0]);
        self::assertArrayNotHasKey('aggregates', $users[0]);
    }

    /**
     * Test that a relation pinning the aggregates renders the child's defaults.
     *
     * @return void
     */
    public function testPinnedAggregatesRenderOnTheChild(): void
    {
        [$posts, $statements] = $this->render(MeteredPostResource::class, new Post, [
            'fields' => ['metered_posts' => 'title,user'],
        ]);

        self::assertStatementCount(2, $statements);
        self::assertSame(['_type', 'id', 'aggregates', 'name'], array_keys((array) $posts[0]['user']));
        self::assertSame(2, $posts[0]['user']['aggregates']['posts_count']);
    }

    /**
     * Test that aggregates listed as a fixed field load and render.
     *
     * @return void
     */
    public function testFixedAggregatesLoadAndRender(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['id', '_type', 'aggregates']);

        [$users] = $this->render(UserResource::class, new User, ['fields' => ['users' => 'name']]);

        self::assertSame(2, $users[0]['aggregates']['posts_count']);
    }

    /**
     * Parse the query, apply the criteria for the resource and encode the
     * collection, recording every statement the read and the encoding run.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $server
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function render(string $resource, Model $model, array $query, array $server = []): array
    {
        assert($this->app !== null);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), $query, [], [], $server);

        $this->app->instance('request', $request);

        ApiQuery::parse($request);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $json     = '';

        $statements = $this->recordStatements(static function () use ($criteria, $resource, $model, $request, &$json): void {
            $rows = $criteria->usingResource($resource)->apply($model)->orderBy('id')->get();
            $json = (string) json_encode($resource::collection($rows)->resolve($request));
        });

        /** @var list<array<string, mixed>> $decoded */
        $decoded = json_decode($json, true);

        return [$decoded, $statements];
    }

    /**
     * Strip identifier quotes so SQL assertions are driver-agnostic.
     *
     * @param  string  $sql
     * @return string
     */
    private function unquote(string $sql): string
    {
        return str_replace(['`', '"'], '', $sql);
    }

    /**
     * Seed users numbered from the first to the last, each with a published and
     * an unpublished post carrying the whole tag pool.
     *
     * @param  int  $first
     * @param  int  $last
     * @return void
     */
    private function seedUsers(int $first, int $last): void
    {
        for ($number = $first; $number <= $last; $number++) {

            $user = User::create([
                'name'            => 'User ' . $number,
                'email'           => 'user' . $number . '@example.com',
                'organization_id' => $this->organizationId,
            ]);

            foreach ([true, false] as $published) {
                Post::create(['user_id' => $user->id, 'title' => 'Post ' . $number, 'body' => 'Content', 'published' => $published])
                    ->tags()
                    ->attach($this->tagIds);
            }
        }
    }
}
