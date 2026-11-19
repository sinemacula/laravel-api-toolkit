<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationAggregateOrder;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\AuthoredPostRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\SortableAuthoredPostResource;
use Tests\Fixtures\Resources\SortableUserResource;
use Tests\TestCase;

/**
 * Feature tests for ordering by declared relation aggregates and by columns of
 * to-one relations, on every engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
#[CoversClass(OrderApplier::class)]
#[CoversClass(RelationAggregateOrder::class)]
final class AggregateRelationSortTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var array<string, int> The seeded users' ids, keyed by name */
    private array $users = [];

    /**
     * Register the users and posts routes and seed authors with posts and
     * articles.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [SortableUserResource::class, SortableAuthoredPostResource::class, ArticleResource::class]);

        Route::middleware(ParseApiQuery::class)->get('/users', static function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SortableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SortableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/posts', static function (AuthoredPostRepository $repository): ApiResourceCollection {

            $posts = $repository->usingResource(SortableAuthoredPostResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($posts, SortableAuthoredPostResource::class);
        });

        // Ada ties Cy on posts but has none published; Cy has no articles
        $this->seedUser('Ada', [false, false], [40]);
        $this->seedUser('Bea', [true], [5, 10]);
        $this->seedUser('Cy', [false, true], []);
    }

    /**
     * Test that a count orders the rows and matches the count the payload
     * renders, breaking the tie by the key in the last direction.
     *
     * @return void
     */
    public function testCountOrdersTheRowsAndMatchesTheRenderedCount(): void
    {
        $response = $this->getJson('/users?order=aggregates.posts_count:desc&fields[sortable_users]=name,aggregates&aggregates[sortable_users]=posts_count');

        $response->assertOk();

        self::assertSame(['Cy', 'Ada', 'Bea'], $response->json('data.*.name'));
        self::assertSame([2, 2, 1], $response->json('data.*.aggregates.posts_count'));
    }

    /**
     * Test that a constrained count orders by the constrained value and leaves
     * the total untouched.
     *
     * @return void
     */
    public function testConstrainedCountOrdersByTheConstrainedValue(): void
    {
        $response = $this->getJson('/users?order=aggregates.published_posts:desc,name&limit=2');

        $response->assertOk();

        self::assertSame(['Bea', 'Cy'], $response->json('data.*.name'));

        $response->assertJsonPath('meta.total', 3);
    }

    /**
     * Test that a sum orders a row with no related rows as NULL, where the
     * engine places NULL.
     *
     * @return void
     */
    public function testSumOrdersARowWithoutRelatedRowsAsNull(): void
    {
        $response = $this->getJson('/users?order=aggregates.article_views&fields[sortable_users]=name,aggregates&aggregates[sortable_users]=article_views');

        $response->assertOk();

        $nullsFirst = DB::connection()->getDriverName() !== 'pgsql';

        self::assertSame($nullsFirst ? ['Cy', 'Bea', 'Ada'] : ['Bea', 'Ada', 'Cy'], $response->json('data.*.name'));
        self::assertNull($response->json('data.' . ($nullsFirst ? 0 : 2) . '.aggregates.article_views'));
    }

    /**
     * Test that a one-of-many relation orders by the row it renders, leaving
     * out a soft-deleted newer row.
     *
     * @return void
     */
    public function testOneOfManyRelationOrdersByTheRowItRenders(): void
    {
        // Ada's newest article is trashed, so her live latest has 40 views
        Article::query()->create($this->article($this->users['Ada'], 1))->delete();

        $response = $this->getJson('/users?order=latestArticle.views:desc,name&fields[sortable_users]=name,latestArticle&fields[articles]=views');

        $response->assertOk();

        $expected = DB::connection()->getDriverName() === 'pgsql'
            ? [['Cy', null], ['Ada', 40], ['Bea', 10]]
            : [['Ada', 40], ['Bea', 10], ['Cy', null]];

        self::assertSame($expected, $this->namesWith($response->json('data'), 'latestArticle.views'));
    }

    /**
     * Test that a relation orders by a boolean column of the row it renders,
     * which no aggregate function reads on every engine.
     *
     * @return void
     */
    public function testRelationOrdersByABooleanColumnOfTheRowItRenders(): void
    {
        $response = $this->getJson('/users?order=latestPost.published:desc,name&fields[sortable_users]=name');

        $response->assertOk();

        self::assertSame(['Bea', 'Cy', 'Ada'], $response->json('data.*.name'));
    }

    /**
     * Test that a belongs-to relation orders by the related column, a post
     * whose author is missing, and so rendered as the default, sorting as NULL.
     *
     * @return void
     */
    public function testBelongsToRelationOrdersByTheRelatedColumn(): void
    {
        Post::query()->create(['user_id' => 999, 'title' => 'Orphan', 'body' => 'Body']);

        $response = $this->getJson('/posts?order=author.name:desc&fields[sortable_posts]=title');

        $response->assertOk();

        $authored = ['Cy', 'Cy', 'Bea', 'Ada', 'Ada'];

        self::assertSame(
            DB::connection()->getDriverName() === 'pgsql' ? ['Orphan', ...$authored] : [...$authored, 'Orphan'],
            $response->json('data.*.title'),
        );
    }

    /**
     * Test that a cursor-paginated read refuses an aggregate key.
     *
     * @return void
     */
    public function testCursorPaginationRefusesAnAggregateKey(): void
    {
        $response = $this->getJson('/users?pagination=cursor&order=aggregates.posts_count');

        $response->assertStatus(422);

        self::assertSame(
            ['order.aggregates.posts_count' => ['The "aggregates.posts_count" key cannot order a cursor-paginated read; use offset pagination.']],
            $response->json('error.meta'),
        );
    }

    /**
     * Test that each aggregate or relation key counts once against the order
     * key cap.
     *
     * @return void
     */
    public function testEachKeyCountsAgainstTheOrderKeyCap(): void
    {
        Config::set('api-toolkit.query_cost.max_order_keys', 1);

        $response = $this->getJson('/users?order=aggregates.posts_count,latestArticle.views');

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta.reason', 'max_order_keys');
        $response->assertJsonPath('error.meta.limit', 1);
        $response->assertJsonPath('error.meta.actual', 2);
    }

    /**
     * Seed a user with posts of the given published states and articles of the
     * given view counts.
     *
     * @param  string  $name
     * @param  list<bool>  $posts
     * @param  list<int>  $articles
     * @return void
     */
    private function seedUser(string $name, array $posts, array $articles): void
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name) . '@example.com']);

        $this->users[$name] = $user->id;

        foreach ($posts as $published) {
            Post::query()->create(['user_id' => $user->id, 'title' => $name, 'body' => 'Body', 'published' => $published]);
        }

        foreach ($articles as $views) {
            Article::query()->create($this->article($user->id, $views));
        }
    }

    /**
     * Build the attributes of an article with the given view count.
     *
     * @param  int  $userId
     * @param  int  $views
     * @return array<string, mixed>
     */
    private function article(int $userId, int $views): array
    {
        $slug = uniqid('article-', true);

        return ['user_id' => $userId, 'title' => $slug, 'slug' => $slug, 'body' => 'Body', 'summary' => 'Summary', 'views' => $views];
    }

    /**
     * Pair each rendered row's name with the value at the given path.
     *
     * @param  mixed  $rows
     * @param  string  $path
     * @return list<array{mixed, mixed}>
     */
    private function namesWith(mixed $rows, string $path): array
    {
        return array_values(array_map(static fn (mixed $row): array => [data_get($row, 'name'), data_get($row, $path)], (array) $rows));
    }
}
