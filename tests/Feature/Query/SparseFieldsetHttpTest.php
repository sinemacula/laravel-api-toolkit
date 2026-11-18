<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\PinnedPostsUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for sparse fieldset resolution over the real HTTP pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversClass(FieldResolver::class)]
#[CoversClass(ValueResolver::class)]
final class SparseFieldsetHttpTest extends TestCase
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

        $this->registerApiExceptionHandler();

        foreach (['/users' => UserResource::class, '/pinned-users' => PinnedPostsUserResource::class] as $uri => $resource) {
            Route::middleware(ParseApiQuery::class)->get($uri, function (UserRepository $repository) use ($resource): ApiResourceCollection {

                $users = $repository->usingResource($resource)->withApiCriteria()->paginate();

                return new ApiResourceCollection($users, $resource);
            });
        }

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        Post::create(['user_id' => $alice->id, 'title' => 'First', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Second', 'body' => 'Content', 'published' => false]);
    }

    /**
     * Test that the `:all` token expands to every declared non-metric field.
     *
     * @return void
     */
    public function testAllTokenExpandsToEveryDeclaredField(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => ':all'],
        ]));

        $response->assertOk();

        /** @var array<string, mixed> $record */
        $record = $response->json('data.0');

        foreach (['id', '_type', 'name', 'email', 'status', 'created_at', 'updated_at', 'full_label', 'display_label'] as $field) {
            self::assertArrayHasKey($field, $record);
        }
    }

    /**
     * Test that the default field set, without aggregate buckets, applies when
     * fields is omitted.
     *
     * @return void
     */
    public function testDefaultFieldSetAppliesWhenFieldsOmitted(): void
    {
        $response = $this->getJson('/users');

        $response->assertOk();

        /** @var array<string, mixed> $record */
        $record = $response->json('data.0');

        foreach (['id', '_type', 'name', 'email'] as $field) {
            self::assertArrayHasKey($field, $record);
        }

        foreach (['status', 'created_at', 'updated_at', 'full_label', 'display_label', 'organization', 'posts', 'aggregates'] as $field) {
            self::assertArrayNotHasKey($field, $record);
        }
    }

    /**
     * Test that computed and accessor fields surface with their closure output.
     *
     * @return void
     */
    public function testComputedAndAccessorFieldsSurface(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'full_label,display_label'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.full_label', 'Alice <alice@example.com>');
        $response->assertJsonPath('data.0.display_label', 'Alice <alice@example.com>');
    }

    /**
     * Test that an undeclared requested field is dropped rather than rejected.
     *
     * @return void
     */
    public function testUndeclaredFieldIsSilentlyDropped(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'name,not_a_real_field'],
        ]));

        $response->assertOk();

        /** @var array<string, mixed> $record */
        $record = $response->json('data.0');

        self::assertArrayHasKey('name', $record);
        self::assertArrayHasKey('id', $record);
        self::assertArrayHasKey('_type', $record);
        self::assertArrayNotHasKey('not_a_real_field', $record);
        self::assertArrayNotHasKey('email', $record);
        self::assertNull($response->json('error'));
    }

    /**
     * Test that a selection naming its own resource again renders the inner
     * level without the relation that would repeat the cycle, without a lazy
     * load and in a statement count the page size does not change.
     *
     * @return void
     */
    public function testCyclicSelectionCutsTheRepeatedLevelAtAConstantStatementCount(): void
    {
        $uri    = '/users?fields[users]=name,posts&fields[posts]=title,user';
        $counts = [];

        $this->getJson($uri)->assertOk();

        foreach ([5, 50] as $total) {

            $this->seedAuthors($total);

            [$response, $counts[]] = $this->countStatements($uri);

            $response->assertJsonCount($total, 'data');
            $response->assertJsonPath('data.0.posts.0.user.name', 'Alice');

            self::assertArrayNotHasKey('posts', (array) $response->json('data.0.posts.0.user'));
        }

        self::assertSame($counts[0], $counts[1]);
    }

    /**
     * Test that a relation's pinned projection renders in place of the one the
     * request selects for its resource, nested relations included, at a
     * statement count the page size does not change.
     *
     * @return void
     */
    public function testPinnedRelationProjectionOverridesTheRequestedOne(): void
    {
        $uri    = '/pinned-users?fields[pinned_posts_users]=name,posts&fields[posts]=body';
        $tag    = Tag::create(['name' => 'php']);
        $counts = [];

        $this->getJson($uri)->assertOk();

        foreach ([5, 50] as $total) {

            $this->seedAuthors($total);

            Post::query()->each(static function (Post $post) use ($tag): void {
                $post->tags()->syncWithoutDetaching([$tag->id]);
            });

            [$response, $counts[]] = $this->countStatements($uri);

            $post = (array) $response->json('data.0.posts.0');

            self::assertEqualsCanonicalizing(['_type', 'id', 'title', 'tags'], array_keys($post));
            self::assertSame(['php'], array_column((array) $post['tags'], 'name'));
        }

        self::assertSame($counts[0], $counts[1]);
    }

    /**
     * Request the URI, returning the response and the statements it ran.
     *
     * @param  string  $uri
     * @return array{\Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>, int}
     */
    private function countStatements(string $uri): array
    {
        $response   = null;
        $statements = $this->recordStatements(function () use (&$response, $uri): void {
            $response = $this->getJson($uri)->assertOk();
        });

        assert($response instanceof TestResponse);

        return [$response, count($statements)];
    }

    /**
     * Seed further authors with a post each until the given total is reached.
     *
     * @param  int  $total
     * @return void
     */
    private function seedAuthors(int $total): void
    {
        for ($index = User::query()->count(); $index < $total; $index++) {

            $user = User::create(['name' => 'Author ' . $index, 'email' => 'author' . $index . '@example.com', 'status' => 'active']);

            Post::create(['user_id' => $user->id, 'title' => 'Post ' . $index, 'body' => 'Content']);
        }
    }
}
