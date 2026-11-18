<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RelationWindow;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\BoundedOrganizationResource;
use Tests\Fixtures\Resources\BoundedPostResource;
use Tests\Fixtures\Resources\BoundedUserResource;
use Tests\Fixtures\Resources\CommentResource;
use Tests\Fixtures\Resources\CountryResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\TestCase;

/**
 * Feature tests for embedded to-many relations bounded per parent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(EagerLoadPlanner::class)]
#[CoversClass(RelationLimit::class)]
#[CoversClass(RelationWindow::class)]
#[CoversClass(ValueResolver::class)]
final class BoundedRelationHttpTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /**
     * Set up the users index, user show and organizations routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            ArticleResource::class,
            BoundedOrganizationResource::class,
            BoundedPostResource::class,
            BoundedUserResource::class,
            CommentResource::class,
            CountryResource::class,
            TagResource::class,
        ]);

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [Article::class => ArticleResource::class]);

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(BoundedUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, BoundedUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/users/{id}', static function (int $id): BoundedUserResource {

            $user = User::query()->findOrFail($id);

            return new BoundedUserResource($user, loadMissing: true);
        });

        Route::middleware(ParseApiQuery::class)->get('/organizations', function (): ApiResourceCollection {

            /** @var array<int|string, \Closure|string> $with */
            $with = BoundedOrganizationResource::eagerLoadMapFor(BoundedOrganizationResource::resolveFields());

            $organizations = Organization::query()->with($with)->orderBy('id')->get();

            return new ApiResourceCollection($organizations, BoundedOrganizationResource::class);
        });
    }

    /**
     * Test that each user gets at most the limit, the lowest keys first, and
     * only the cut one is flagged.
     *
     * @return void
     */
    public function testEachParentIsBoundedSeparatelyAndOnlyTheCutOneIsFlagged(): void
    {
        [$empty, $five, $six] = $this->seedUsers([0, 5, 6]);

        $data = null;

        $statements = $this->recordStatements(function () use (&$data): void {
            $data = $this->getJson('/users?fields[bounded_users]=name,posts&fields[bounded_posts]=title')->assertOk()->json('data');
        });

        $users = array_column(self::rows($data), null, 'id');

        self::assertSame([], $users[$empty]['posts']);
        self::assertArrayNotHasKey('_truncated', $users[$empty]);
        self::assertSame($this->postTitles($five, 5), array_column(self::rows($users[$five]['posts']), 'title'));
        self::assertArrayNotHasKey('_truncated', $users[$five]);
        self::assertSame($this->postTitles($six, 5), array_column(self::rows($users[$six]['posts']), 'title'));
        self::assertSame(['posts'], $users[$six]['_truncated']);

        $postQueries = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'posts')));

        self::assertCount(1, $postQueries);
        self::assertStringContainsString('laravel_row', $postQueries[0]);
    }

    /**
     * Test that a show endpoint loading its relations after the fetch bounds
     * and flags them as a collection endpoint does.
     *
     * @return void
     */
    public function testShowEndpointBoundsAndFlagsTheLoadedRelation(): void
    {
        [$user] = $this->seedUsers([7]);

        $data = null;

        $statements = $this->recordStatements(function () use (&$data, $user): void {
            $data = $this->getJson('/users/' . $user . '?fields[bounded_users]=name,posts&fields[bounded_posts]=title')->assertOk()->json('data');
        });

        $record = self::record($data);

        self::assertSame(['posts'], $record['_truncated']);
        self::assertSame($this->postTitles($user, 5), array_column(self::rows($record['posts']), 'title'));

        $postQueries = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'posts')));

        self::assertCount(1, $postQueries);
        self::assertStringContainsString('laravel_row', $postQueries[0]);
    }

    /**
     * Test that an ordering constraint gives each user its newest records, with
     * tied timestamps resolved by key, under the alias it renders as.
     *
     * @return void
     */
    public function testOrderingConstraintKeepsTheNewestWithTiesResolvedByKey(): void
    {
        [$user] = $this->seedUsers([6]);

        $posts = Post::query()->where('user_id', $user)->orderBy('id')->pluck('id')->all();

        Post::query()->whereKey($posts[0])->update(['created_at' => Carbon::parse('2026-01-02 00:00:00')]);
        Post::query()->whereKey(array_slice($posts, 1))->update(['created_at' => Carbon::parse('2026-01-01 00:00:00')]);

        $response = $this->getJson('/users?fields[bounded_users]=name,latest_posts&fields[bounded_posts]=id');

        $response->assertOk();
        $response->assertJsonPath('data.0._truncated', ['latest_posts']);

        self::assertSame(array_slice($posts, 0, 5), array_column(self::rows($response->json('data.0.latest_posts')), 'id'));
    }

    /**
     * Provide both orders of selecting the two fields.
     *
     * @return iterable<string, array{string}>
     */
    public static function aliasOrderProvider(): iterable
    {
        yield 'posts first' => ['name,posts,latest_posts'];
        yield 'latest posts first' => ['name,latest_posts,posts'];
    }

    /**
     * Test that two fields over relations to one model each render their own
     * rows, in whichever order they are selected.
     *
     * @param  string  $selection
     * @return void
     */
    #[DataProvider('aliasOrderProvider')]
    public function testTwoFieldsOverOneModelRenderTheirOwnRows(string $selection): void
    {
        [$user] = $this->seedUsers([7]);

        $posts = Post::query()->where('user_id', $user)->orderBy('id')->pluck('id')->all();

        foreach ($posts as $offset => $post) {
            Post::query()->whereKey($post)->update(['created_at' => Carbon::parse('2026-01-01 00:00:00')->addDays($offset)]);
        }

        $response = $this->getJson('/users?fields[bounded_users]=' . $selection . '&fields[bounded_posts]=id');

        $response->assertOk();

        self::assertSame(array_slice($posts, 0, 5), array_column(self::rows($response->json('data.0.posts')), 'id'));
        self::assertSame(array_slice(array_reverse($posts), 0, 5), array_column(self::rows($response->json('data.0.latest_posts')), 'id'));
        self::assertEqualsCanonicalizing(['posts', 'latest_posts'], (array) $response->json('data.0._truncated'));
    }

    /**
     * Test that a nested selection bounds both levels, many-to-many included.
     *
     * @return void
     */
    public function testNestedSelectionBoundsBothLevels(): void
    {
        [$user] = $this->seedUsers([6]);

        $tags = collect(['red', 'green', 'blue'])->map(static fn (string $name): int => Tag::create(['name' => $name])->id)->all();

        Post::query()->where('user_id', $user)->get()->each(static function (Post $post) use ($tags): void {
            $post->tags()->attach($tags);
        });

        $response = $this->getJson('/users?fields[bounded_users]=name,posts&fields[bounded_posts]=title,tags&fields[tags]=name');

        $response->assertOk();
        $response->assertJsonCount(5, 'data.0.posts');
        $response->assertJsonPath('data.0._truncated', ['posts']);

        foreach (self::rows($response->json('data.0.posts')) as $post) {
            self::assertSame(['red', 'green'], array_column(self::rows($post['tags']), 'name'));
            self::assertSame(['tags'], $post['_truncated']);
        }
    }

    /**
     * Test that a relation to a string-keyed model is bounded per parent in the
     * related keys' lexical order.
     *
     * @return void
     */
    public function testStringKeyedRelationIsBoundedInKeyOrder(): void
    {
        [$user] = $this->seedUsers([2]);

        foreach (['GB' => 'United Kingdom', 'FR' => 'France', 'DE' => 'Germany'] as $code => $name) {
            Country::create(['code' => $code, 'name' => $name]);
        }

        Post::query()->where('user_id', $user)->get()->each(static function (Post $post): void {
            $post->countries()->attach(['GB', 'FR', 'DE']);
        });

        $data = null;

        $statements = $this->recordStatements(function () use (&$data): void {
            $data = $this->getJson('/users?fields[bounded_users]=name,posts&fields[bounded_posts]=title,countries&fields[countries]=code')
                ->assertOk()
                ->assertJsonCount(2, 'data.0.posts')
                ->json('data.0.posts');
        });

        foreach (self::rows($data) as $post) {
            self::assertSame(['DE', 'FR'], array_column(self::rows($post['countries']), 'code'));
            self::assertSame(['countries'], $post['_truncated']);
        }

        $countryQueries = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'countries')));

        self::assertCount(1, $countryQueries);
        self::assertStringContainsString('laravel_row', $countryQueries[0]);
    }

    /**
     * Test that a has-many-through is bounded per far parent.
     *
     * @return void
     */
    public function testHasManyThroughIsBoundedPerParent(): void
    {
        $large = Organization::create(['name' => 'Large', 'slug' => 'large']);
        $small = Organization::create(['name' => 'Small', 'slug' => 'small']);

        $first  = User::create(['name' => 'First', 'email' => 'first@example.com', 'organization_id' => $large->id]);
        $second = User::create(['name' => 'Second', 'email' => 'second@example.com', 'organization_id' => $large->id]);
        $third  = User::create(['name' => 'Third', 'email' => 'third@example.com', 'organization_id' => $small->id]);

        foreach ([$first, $third, $second, $first, $third, $second, $first, $second] as $index => $author) {
            Post::create(['user_id' => $author->id, 'title' => 'Post ' . $index, 'body' => 'Body']);
        }

        $response = $this->getJson('/organizations?fields[bounded_posts]=title');

        $response->assertOk();
        $response->assertJsonPath('data.0.posts.*.title', ['Post 0', 'Post 2', 'Post 3', 'Post 5', 'Post 6']);
        $response->assertJsonPath('data.0._truncated', ['posts']);
        $response->assertJsonPath('data.1.posts.*.title', ['Post 1', 'Post 4']);

        self::assertArrayNotHasKey('_truncated', self::record($response->json('data.1')));
    }

    /**
     * Test that the default caps a relation declaring no limit.
     *
     * @return void
     */
    public function testDefaultCapsAnUndeclaredRelation(): void
    {
        Config::set('api-toolkit.resources.relation_limit', 2);

        $this->seedComments(3);

        $response = $this->getJson('/users?fields[bounded_users]=name,comments&fields[comments]=body');

        $response->assertOk();
        $response->assertJsonPath('data.0.comments.*.body', ['Comment 1', 'Comment 2']);
        $response->assertJsonPath('data.0._truncated', ['comments']);
    }

    /**
     * Test that a disabled default leaves an undeclared relation unbounded.
     *
     * @return void
     */
    public function testDisabledDefaultLeavesAnUndeclaredRelationUnbounded(): void
    {
        Config::set('api-toolkit.resources.relation_limit', 0);

        $this->seedComments(30);

        $response = $this->getJson('/users?fields[bounded_users]=name,comments&fields[comments]=body');

        $response->assertOk();
        $response->assertJsonCount(30, 'data.0.comments');

        self::assertArrayNotHasKey('_truncated', self::record($response->json('data.0')));
    }

    /**
     * Test that a soft-deleting relation keeps its live rows in key order under
     * the bound, and that a root which does not soft delete refuses to open its
     * relations to trashed rows.
     *
     * @return void
     */
    public function testTrashedRowsStayOutsideTheBoundUnderALiveOnlyRoot(): void
    {
        $author = User::create(['name' => 'Author', 'email' => 'author@example.com']);

        $removed = $this->seedArticle($author->id, 'removed');
        $this->seedArticle($author->id, 'first');
        $this->seedArticle($author->id, 'second');

        $removed->delete();

        $live = $this->getJson('/users?fields[bounded_users]=name,articles&fields[articles]=slug');

        $live->assertOk();
        $live->assertJsonPath('data.0.articles.*.slug', ['first', 'second']);

        self::assertArrayNotHasKey('_truncated', self::record($live->json('data.0')));

        $this->getJson('/users?trashed=with&fields[bounded_users]=name,articles&fields[articles]=slug')
            ->assertUnprocessable()
            ->assertJsonPath('error.meta.trashed.0', fn ($message): bool => is_string($message) && $message !== '');
    }

    /**
     * Seed one user per count, writing that many posts each, interleaved so a
     * page-wide bound would starve the later users.
     *
     * @param  array<int, int>  $counts
     * @return array<int, int>
     */
    private function seedUsers(array $counts): array
    {
        $users = [];

        foreach ($counts as $index => $count) {
            $users[$index] = User::create(['name' => 'User ' . $index, 'email' => 'user' . $index . '@example.com'])->id;
        }

        for ($round = 1; $round <= max([0, ...$counts]); $round++) {
            foreach ($counts as $index => $count) {
                if ($round > $count) {
                    continue;
                }

                Post::create(['user_id' => $users[$index], 'title' => 'User ' . $index . ' post ' . $round, 'body' => 'Body']);
            }
        }

        return $users;
    }

    /**
     * Return the titles of the user's lowest-keyed posts.
     *
     * @param  int  $user
     * @param  int  $count
     * @return array<int, string>
     */
    private function postTitles(int $user, int $count): array
    {
        /** @var array<int, string> */
        return Post::query()->where('user_id', $user)->orderBy('id')->limit($count)->pluck('title')->all();
    }

    /**
     * Seed a user with the given number of comments.
     *
     * @param  int  $count
     * @return void
     */
    private function seedComments(int $count): void
    {
        $user = User::create(['name' => 'Commenter', 'email' => 'commenter@example.com']);

        for ($index = 1; $index <= $count; $index++) {
            Comment::create(['user_id' => $user->id, 'body' => 'Comment ' . $index]);
        }
    }

    /**
     * Create an article for the given author.
     *
     * @param  int  $authorId
     * @param  string  $slug
     * @return \Tests\Fixtures\Models\Article
     */
    private function seedArticle(int $authorId, string $slug): Article
    {
        return Article::create([
            'user_id' => $authorId,
            'title'   => ucfirst($slug),
            'slug'    => $slug,
            'body'    => 'Body',
            'summary' => 'Summary',
        ]);
    }

    /**
     * Assert the decoded value is a list of records and return it.
     *
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertContainsOnlyArray($value);

        /** @var array<int, array<string, mixed>> */
        return $value;
    }

    /**
     * Assert the decoded value is a record and return it.
     *
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    private static function record(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> */
        return $value;
    }
}
