<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\PivotPostResource;
use Tests\Fixtures\Resources\PivotUserResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\TestCase;

/**
 * Feature tests for rendering a many-to-many relation's pivot columns on its
 * embedded children, under strict models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversClass(EagerLoadPlanner::class)]
#[CoversClass(ValueResolver::class)]
final class PivotColumnsHttpTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /** @var int The post whose roled tags outnumber the bound */
    private int $first;

    /** @var int The post with one tag */
    private int $second;

    /** @var array<string, int> The tag keys, by name */
    private array $tags = [];

    /**
     * Register the routes and seed two posts with roled tags.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [PivotPostResource::class, PivotUserResource::class, TagResource::class]);

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        $this->registerApiExceptionHandler();
        $this->registerRoutes();
        $this->seedGraph();

        Model::shouldBeStrict();
    }

    /**
     * Restore lenient models and clear the schema caches.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Model::shouldBeStrict(false);

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that each embedded tag renders its own edge's role, within the
     * pivot-constrained bound, and no window key leaks.
     *
     * @return void
     */
    public function testEachTagRendersItsOwnRoleWithinThePivotConstrainedBound(): void
    {
        $content    = null;
        $statements = $this->recordStatements(function () use (&$content): void {
            $content = $this->getJson('/users?fields[pivot_users]=name,posts&fields[pivot_posts]=title,tags')->assertOk()->baseResponse->getContent();
        });

        /** @var array{data: list<array{posts: list<array<string, mixed>>}>} $decoded */
        $decoded = json_decode((string) $content, true, flags: JSON_THROW_ON_ERROR);
        $posts   = array_column($decoded['data'][0]['posts'], null, 'id');

        self::assertSame([$this->tag('php', 'primary'), $this->tag('laravel', 'secondary')], $posts[$this->first]['tags']);
        self::assertSame(['tags'], $posts[$this->first]['_truncated']);
        self::assertSame([$this->tag('php', 'secondary')], $posts[$this->second]['tags']);
        self::assertArrayNotHasKey('_truncated', $posts[$this->second]);
        self::assertStringNotContainsString('laravel_row', (string) $content);

        $tagQueries = array_values(array_filter(self::unquoted($statements), static fn (string $sql): bool => str_contains($sql, 'post_tag.role as pivot_role')));

        self::assertCount(1, $tagQueries);
        self::assertStringContainsString('laravel_row', $tagQueries[0]);
    }

    /**
     * Test that a show endpoint loading its relations after the fetch renders
     * the pivot columns, while the same tag outside the relation renders none.
     *
     * @return void
     */
    public function testOnlyATagReachedThroughTheRelationCarriesThePivot(): void
    {
        $this->getJson('/posts/' . $this->second . '?fields[pivot_posts]=title,tags')
            ->assertOk()
            ->assertJsonPath('data.tags', [$this->tag('php', 'secondary')]);

        $this->getJson('/tags/' . $this->tags['php'])
            ->assertOk()
            ->assertJsonMissingPath('data.pivot');
    }

    /**
     * Test that a casting pivot loaded under its own accessor renders its cast
     * values under the pivot key, a present null included.
     *
     * @return void
     */
    public function testACastingPivotUnderItsOwnAccessorRendersUnderThePivotKey(): void
    {
        /** @var list<array<string, mixed>> $tags */
        $tags = $this->getJson('/posts/' . $this->first . '?fields[pivot_posts]=typed_tags')->assertOk()->json('data.typed_tags');

        self::assertSame(['role' => 'primary', 'tagged_at' => '2026-01-01T10:00:00.000000Z'], $tags[0]['pivot']);
        self::assertSame(['role' => null, 'tagged_at' => null], $tags[3]['pivot']);
        self::assertArrayNotHasKey('tagging', $tags[0]);
    }

    /**
     * Test that narrowing the tags to the columns they render keeps the pivot
     * columns and changes nothing in the response.
     *
     * @return void
     */
    public function testNarrowedTagsKeepTheirPivotColumns(): void
    {
        $uri = '/users?fields[pivot_users]=name,posts&fields[pivot_posts]=title,tags&fields[tags]=name';

        Config::set('api-toolkit.resources.narrow_columns', false);

        $unnarrowed = $this->getJson($uri)->assertOk()->baseResponse->getContent();

        Config::set('api-toolkit.resources.narrow_columns', true);

        $narrowed   = null;
        $statements = $this->recordStatements(function () use ($uri, &$narrowed): void {
            $narrowed = $this->getJson($uri)->assertOk()->baseResponse->getContent();
        });

        $select = 'select tags.name, tags.id, post_tag.post_id as pivot_post_id, post_tag.tag_id as pivot_tag_id, post_tag.role as pivot_role';

        self::assertSame($unnarrowed, $narrowed);
        self::assertNotSame([], array_filter(self::unquoted($statements), static fn (string $sql): bool => str_contains($sql, $select)));
    }

    /**
     * Build the record a tag renders with the given pivot role.
     *
     * @param  string  $name
     * @param  string  $role
     * @return array<string, mixed>
     */
    private function tag(string $name, string $role): array
    {
        return ['_type' => 'tags', 'id' => $this->tags[$name], 'name' => $name, 'pivot' => ['role' => $role]];
    }

    /**
     * Strip the identifier quotes from the statements.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private static function unquoted(array $statements): array
    {
        return array_map(static fn (string $sql): string => str_replace(['`', '"'], '', $sql), $statements);
    }

    /**
     * Register the users index, post show and tag show routes.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        Route::middleware(ParseApiQuery::class)->get('/users', static function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(PivotUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, PivotUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get(
            '/posts/{id}',
            static fn (int $id): PivotPostResource => new PivotPostResource(Post::query()->findOrFail($id), loadMissing: true),
        );

        Route::middleware(ParseApiQuery::class)->get('/tags/{id}', static fn (int $id): TagResource => new TagResource(Tag::query()->findOrFail($id)));
    }

    /**
     * Seed a user with two posts: the first with three roled tags and one
     * without a role, the second sharing one tag in another role.
     *
     * @return void
     */
    private function seedGraph(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        foreach (['php', 'laravel', 'testing', 'draft'] as $name) {
            $this->tags[$name] = Tag::create(['name' => $name])->id;
        }

        $first  = Post::create(['user_id' => $user->id, 'title' => 'First', 'body' => 'Body']);
        $second = Post::create(['user_id' => $user->id, 'title' => 'Second', 'body' => 'Body']);

        $first->tags()->attach([
            $this->tags['php']     => ['role' => 'primary', 'tagged_at' => Carbon::parse('2026-01-01 10:00:00')],
            $this->tags['laravel'] => ['role' => 'secondary', 'tagged_at' => null],
            $this->tags['testing'] => ['role' => 'secondary', 'tagged_at' => null],
            $this->tags['draft']   => ['role' => null, 'tagged_at' => null],
        ]);

        $second->tags()->attach($this->tags['php'], ['role' => 'secondary']);

        $this->first  = $first->id;
        $this->second = $second->id;
    }
}
