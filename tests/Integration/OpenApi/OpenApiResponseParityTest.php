<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\CreatesDocumentTables;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Controllers\ArticleController;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\ParityDocumentController;
use Tests\Fixtures\OpenApi\ParityShapeController;
use Tests\Fixtures\OpenApi\ParityUserController;
use Tests\Fixtures\Policies\ArticlePolicy;
use Tests\Fixtures\Resources\AggregatingUserResource;
use Tests\Fixtures\Resources\BoundedPostResource;
use Tests\Fixtures\Resources\BoundedUserResource;
use Tests\Fixtures\Resources\DocumentResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TaggedArticleResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Parity test between the success responses the application serves and the
 * responses the exported document gives their operations.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiComponents::class)]
#[CoversClass(PathBuilder::class)]
final class OpenApiResponseParityTest extends TestCase
{
    use CreatesDocumentTables, ValidatesOpenApiDocuments;

    /** @var array<class-string, class-string> The resource documented and rendered for each model */
    private const array RESOURCES = [
        User::class         => UserResource::class,
        Organization::class => OrganizationResource::class,
        Post::class         => PostResource::class,
        Tag::class          => TagResource::class,
        Article::class      => TaggedArticleResource::class,
        Document::class     => DocumentResource::class,
    ];

    /** @var array<string, string> The key of each seeded record, by its placeholder in a request URI */
    private array $keys = [];

    /**
     * Route the fixture controllers and seed one record of every shape the
     * responses render.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        config()->set('api-toolkit.resources.resource_map', self::RESOURCES);

        Gate::policy(Article::class, ArticlePolicy::class);

        Route::middleware([ParseApiQuery::class, SubstituteBindings::class])->group(static function (): void {
            Route::apiResource('users', ParityUserController::class)->only(['index', 'show']);
            Route::get('documents/{document}', [ParityDocumentController::class, 'show']);
            Route::get('feed', [ParityShapeController::class, 'feed']);
            Route::get('feed/paged', [ParityShapeController::class, 'pagedFeed']);
            Route::get('reports/sales', [ParityShapeController::class, 'sales']);
            Route::post('sign-in', [ParityShapeController::class, 'signIn']);
        });

        Route::apiResource('articles', ArticleController::class)->middleware([ParseApiQuery::class, 'auth', SubstituteBindings::class]);

        $this->seedRecords();
    }

    /**
     * Tear down each test, clearing the compiled schema cache.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Provide each request with the operation that documents it, the documented
     * headers its response carries, values that show it renders the case, and
     * any body it sends and resources it renders in place of the defaults.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: list<string>, 4: array<string, mixed>, 5?: array<string, mixed>, 6?: array<class-string, class-string>}>
     */
    public static function responseProvider(): iterable
    {
        $bounded = [User::class => BoundedUserResource::class, Post::class => BoundedPostResource::class];

        yield 'default index' => ['GET', '/users', '/users', ['Total-Count'], ['meta.total' => 3]];
        yield 'sparse fieldset' => ['GET', '/users', '/users?fields[users]=name', ['Total-Count'], ['data.0.name' => 'Ada', 'data.0.email' => null]];
        yield 'nested sparse fieldset' => [
            'GET', '/users', '/users?fields[users]=posts&fields[posts]=title', ['Total-Count'],
            ['data.0.posts.0.title' => 'Post 1', 'data.0.posts.0.body' => null],
        ];
        yield 'to-one relation held and empty' => [
            'GET', '/users', '/users?fields[users]=organization', ['Total-Count'],
            ['data.0.organization.name' => 'Acme', 'data.1.organization' => null],
        ];
        yield 'aggregates' => [
            'GET', '/users', '/users?fields[users]=aggregates&aggregates[users]=posts_count,posts_sum_id,posts_avg_id', ['Total-Count'],
            ['data.0.aggregates.posts_count' => 6, 'data.2.aggregates.posts_sum_id' => null],
        ];
        yield 'every field' => ['GET', '/users', '/users?fields[users]=:all', ['Total-Count'], ['data.0.full_label' => 'Ada <ada@example.com>']];
        yield 'offset page' => ['GET', '/users', '/users?limit=1&page=2', ['Total-Count'], ['data.0.name' => 'Bob', 'meta.total' => 3]];
        yield 'cursor page' => ['GET', '/users', '/users?pagination=cursor&limit=1', [], ['data.0.name' => 'Ada', 'meta.continue' => true]];
        yield 'show' => ['GET', '/users/{user}', '/users/{ada}', [], ['data.name' => 'Ada']];
        yield 'aggregates over rows and none' => [
            'GET', '/users', '/users', ['Total-Count'],
            ['data.0.aggregates.has_published_posts' => true, 'data.2.aggregates.last_posted_at' => null],
            [], [User::class => AggregatingUserResource::class],
        ];
        yield 'bounded relation cut' => ['GET', '/users/{user}', '/users/{ada}?fields[bounded_users]=posts', [], ['data._truncated' => ['posts']], [], $bounded];
        yield 'bounded relation uncut' => [
            'GET', '/users/{user}', '/users/{bob}?fields[bounded_users]=posts', [],
            ['data.posts.1.title' => 'Post 2', 'data._truncated' => null], [], $bounded,
        ];
        yield 'versioned show' => ['GET', '/documents/{document}', '/documents/{document}', ['ETag'], ['data.title' => 'Draft']];
        yield 'article index' => [
            'GET', '/articles', '/articles?fields[tagged_articles]=title,author,tags,aggregates&aggregates[tagged_articles]=tags_count&fields[users]=name&fields[tags]=name',
            ['Total-Count'], ['data.0.author.name' => 'Ada', 'data.0.tags.0.name' => 'news', 'data.0.aggregates.tags_count' => 1],
        ];
        yield 'article show' => ['GET', '/articles/{article}', '/articles/{article}', [], ['data.slug' => 'title']];
        yield 'article store' => [
            'POST', '/articles', '/articles', [], ['data.title' => 'Launch'],
            ['title' => 'Launch', 'slug' => 'launch', 'body' => 'Body', 'summary' => 'Summary'],
        ];
        yield 'article update' => ['PATCH', '/articles/{article}', '/articles/{article}', [], ['data.status' => 'draft'], ['status' => 'draft']];
        yield 'article destroy' => ['DELETE', '/articles/{article}', '/articles/{article}', [], []];
        yield 'polymorphic list' => ['GET', '/feed', '/feed', [], ['data.1._type' => 'organizations', 'data.2._type' => 'posts']];
        yield 'polymorphic page' => ['GET', '/feed/paged', '/feed/paged', ['Total-Count'], ['data.0._type' => 'users', 'meta.total' => 3]];
        yield 'report page' => ['GET', '/reports/sales', '/reports/sales', ['Total-Count'], ['data.1.orders' => 98]];
        yield 'declared union' => ['POST', '/sign-in', '/sign-in', ['Set-Cookie'], ['data.status' => 'signed_in']];
    }

    /**
     * Test that each success response answers under a documented status, with a
     * body valid against that response's schema and exactly the documented
     * headers expected, each valid against its schema.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  string  $uri
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $body
     * @param  array<class-string, class-string>  $resources
     * @return void
     */
    #[DataProvider('responseProvider')]
    public function testEverySuccessResponseMatchesItsDocumentedResponse(
        string $method,
        string $path,
        string $uri,
        array $headers,
        array $values,
        array $body = [],
        array $resources = [],
    ): void {
        config()->set('api-toolkit.resources.resource_map', [...self::RESOURCES, ...$resources]);

        $document = $this->exportedDocument();
        $response = $this->json($method, strtr($uri, $this->keys), $body)->assertSuccessful();

        foreach ($values as $key => $value) {
            $response->assertJsonPath($key, $value);
        }

        $status   = (string) $response->baseResponse->getStatusCode();
        $pointer  = '/paths/' . $this->escape($path) . '/' . strtolower($method) . '/responses/' . $status;
        $declared = $this->resolveReference($document, '#' . rawurldecode($pointer));

        self::assertIsArray($declared, sprintf('%s %s answers %s, which the document does not list.', $method, $path, $status));

        $content = (string) $response->baseResponse->getContent();

        if (!isset($declared['content'])) {
            self::assertSame('', $content);
        } else {
            $payload = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            $result  = $this->validateAgainstExported($document, $this->renderedShape($document, $pointer . '/content/application~1json/schema', $headers, $payload), $payload);

            self::assertTrue($result->isValid(), sprintf('The %s %s body breaks its documented schema: %s', $method, $uri, $this->formatErrors($result)));
        }

        /** @var array<string, array<string, mixed>> $documented */
        $documented = $declared['headers'] ?? [];

        /** @var array<string, array<string, mixed>> $components */
        $components = $document['components']['headers'];
        $carried    = array_values(array_filter(array_keys($documented), $response->headers->has(...)));
        $shared     = array_filter(array_keys($components), $response->headers->has(...));

        self::assertEqualsCanonicalizing($headers, $carried);
        self::assertSame([], array_values(array_diff($shared, $carried)), 'The response carries a shared header its operation does not document.');

        foreach ($carried as $name) {
            $this->assertHeaderMatches($document, $documented[$name], $pointer . '/headers/' . $this->escape($name), (string) $response->headers->get($name));
        }
    }

    /**
     * Create the tables of the versioned document.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createDocumentTables();
    }

    /**
     * Assert a header value is valid against the schema of the header object it
     * is documented by.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $header
     * @param  string  $pointer
     * @param  string  $value
     * @return void
     */
    private function assertHeaderMatches(array $document, array $header, string $pointer, string $value): void
    {
        if (is_string($header['$ref'] ?? null)) {
            $pointer = substr($header['$ref'], 1);
        }

        $schema = $this->resolveReference($document, '#' . rawurldecode($pointer) . '/schema');

        self::assertIsArray($schema);

        $typed  = ($schema['type'] ?? null) === 'integer' ? filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value : $value;
        $result = $this->validateAgainstExported($document, $pointer . '/schema', $typed);

        self::assertTrue($result->isValid(), sprintf('The header at %s breaks its schema: %s', $pointer, $this->formatErrors($result)));
    }

    /**
     * Point at the collection shape the case expects, since a page with a
     * malformed meta still satisfies the open members of the anyOf around it.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $pointer
     * @param  list<string>  $headers
     * @param  mixed  $payload
     * @return string
     */
    private function renderedShape(array $document, string $pointer, array $headers, mixed $payload): string
    {
        $schema = $this->resolveReference($document, '#' . rawurldecode($pointer));

        if (!is_array($schema) || !isset($schema['anyOf'])) {
            return $pointer;
        }

        return $pointer . '/anyOf/' . match (true) {
            in_array('Total-Count', $headers, true)      => 0,
            is_object($payload) && isset($payload->meta) => 1,
            default                                      => 2,
        };
    }

    /**
     * Seed a user in an organization with more posts than a bounded relation
     * shows, one with fewer posts and no organization, one with neither, a
     * tagged article and a versioned document.
     *
     * @return void
     */
    private function seedRecords(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $ada          = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'organization_id' => $organization->id]);
        $bob          = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        User::create(['name' => 'Cy', 'email' => 'cy@example.com']);

        foreach ([$ada->id => 6, $bob->id => 2] as $user => $count) {
            for ($post = 1; $post <= $count; $post++) {
                Post::create(['user_id' => $user, 'title' => 'Post ' . $post, 'body' => 'Body', 'published' => $post % 2 === 0]);
            }
        }

        $article = Article::create(['user_id' => $ada->id, 'title' => 'Title', 'slug' => 'title', 'body' => 'Body', 'summary' => 'Summary', 'status' => 'published']);

        $article->tags()->attach(Tag::create(['name' => 'news']));

        $this->keys = [
            '{ada}'      => (string) $ada->id,
            '{bob}'      => (string) $bob->id,
            '{article}'  => (string) $article->id,
            '{document}' => (string) Document::create(['title' => 'Draft'])->id,
        ];

        $this->actingAs(ActorUser::query()->findOrFail($ada->id));
    }

    /**
     * Escape a token for a JSON pointer in a URI fragment.
     *
     * @param  string  $token
     * @return string
     */
    private function escape(string $token): string
    {
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $token));
    }

    /**
     * Export the document the responses are validated against.
     *
     * @return array<string, mixed>
     */
    private function exportedDocument(): array
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->app->make(ExportOpenApiComponents::class);

        return $exporter->export()->document;
    }
}
