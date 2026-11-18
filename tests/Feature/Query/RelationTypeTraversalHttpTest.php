<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ProfileResource;
use Tests\Fixtures\Resources\TraversingOrganizationResource;
use Tests\Fixtures\Resources\TraversingPostResource;
use Tests\Fixtures\Resources\TraversingUserResource;
use Tests\TestCase;

/**
 * Feature tests for filters descending through each kind of relation beyond a
 * plain belongs-to or has-many.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(RelationTraversal::class)]
final class RelationTypeTraversalHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var array<string, array{class-string<\Illuminate\Database\Eloquent\Model>, class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>}> */
    private const array ROUTES = [
        '/posts'         => [Post::class, TraversingPostResource::class],
        '/users'         => [User::class, TraversingUserResource::class],
        '/organizations' => [Organization::class, TraversingOrganizationResource::class],
    ];

    /**
     * Set up a route per root and seed rows reaching, and missing, each
     * relation.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [Profile::class => ProfileResource::class]);

        foreach (self::ROUTES as $uri => [$model, $resource]) {
            Route::middleware(ParseApiQuery::class)->get($uri, static function (ApiCriteria $criteria) use ($model, $resource): ApiResourceCollection {
                return new ApiResourceCollection($criteria->usingResource($resource)->apply(new $model)->get(), $resource);
            });
        }

        $this->seedRelations();
    }

    /**
     * Test that a filter through the relation matches exactly the rows it
     * reaches, and its existence checks split the rows between them.
     *
     * @param  string  $uri
     * @param  array<string, mixed>  $filters
     * @param  string  $label
     * @param  array<int, string>  $expected
     * @return void
     */
    #[DataProvider('traversalProvider')]
    public function testFilterThroughTheRelationMatchesExactlyTheRowsItReaches(string $uri, array $filters, string $label, array $expected): void
    {
        $response = $this->getJson($uri . '?filters=' . urlencode((string) json_encode($filters)));

        $response->assertOk();

        self::assertEqualsCanonicalizing($expected, array_column((array) $response->json('data'), $label));
    }

    /**
     * Provide a match, a miss and both existence checks through a pivot, a
     * string-keyed pivot, a has-one and a has-many-through.
     *
     * @return iterable<string, array{string, array<string, mixed>, string, array<int, string>}>
     */
    public static function traversalProvider(): iterable
    {
        yield 'pivot match' => ['/posts', ['tags' => ['name' => 'red']], 'title', ['Launch']];
        yield 'pivot miss' => ['/posts', ['tags' => ['name' => 'green']], 'title', []];
        yield 'pivot $has' => ['/posts', ['$has' => 'tags'], 'title', ['Launch', 'Recap']];
        yield 'pivot $hasnt' => ['/posts', ['$hasnt' => 'tags'], 'title', ['Draft']];

        yield 'string-keyed pivot match' => ['/posts', ['countries' => ['name' => 'France']], 'title', ['Draft']];
        yield 'string-keyed pivot miss' => ['/posts', ['countries' => ['code' => 'DE']], 'title', []];
        yield 'string-keyed pivot $has' => ['/posts', ['$has' => 'countries'], 'title', ['Launch', 'Draft']];
        yield 'string-keyed pivot $hasnt' => ['/posts', ['$hasnt' => 'countries'], 'title', ['Recap']];

        yield 'has-one match' => ['/users', ['profile' => ['bio' => 'Writer']], 'name', ['Alice']];
        yield 'has-one miss' => ['/users', ['profile' => ['bio' => 'Painter']], 'name', []];
        yield 'has-one $has' => ['/users', ['$has' => 'profile'], 'name', ['Alice']];
        yield 'has-one $hasnt' => ['/users', ['$hasnt' => 'profile'], 'name', ['Bob', 'Carol']];

        yield 'has-many-through match' => ['/organizations', ['posts' => ['title' => 'Recap']], 'name', ['Acme']];
        yield 'has-many-through miss' => ['/organizations', ['posts' => ['title' => 'Unwritten']], 'name', []];
        yield 'has-many-through $has' => ['/organizations', ['$has' => 'posts'], 'name', ['Acme']];
        yield 'has-many-through $hasnt' => ['/organizations', ['$hasnt' => 'posts'], 'name', ['Empty']];
    }

    /**
     * Seed two organizations, three users and three posts with their tags,
     * countries and one profile.
     *
     * @return void
     */
    private function seedRelations(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

        Organization::create(['name' => 'Empty', 'slug' => 'empty']);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'organization_id' => $acme->id]);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'organization_id' => $acme->id]);

        User::create(['name' => 'Carol', 'email' => 'carol@example.com']);

        Profile::create(['user_id' => $alice->id, 'bio' => 'Writer']);

        foreach (['GB' => 'United Kingdom', 'FR' => 'France', 'DE' => 'Germany'] as $code => $name) {
            Country::create(['code' => $code, 'name' => $name]);
        }

        $launch = Post::create(['user_id' => $alice->id, 'title' => 'Launch', 'body' => 'Body']);
        $recap  = Post::create(['user_id' => $bob->id, 'title' => 'Recap', 'body' => 'Body']);
        $draft  = Post::create(['user_id' => $alice->id, 'title' => 'Draft', 'body' => 'Body']);

        $launch->tags()->attach(Tag::create(['name' => 'red']));
        $recap->tags()->attach(Tag::create(['name' => 'blue']));
        $launch->countries()->attach('GB');
        $draft->countries()->attach('FR');
    }
}
