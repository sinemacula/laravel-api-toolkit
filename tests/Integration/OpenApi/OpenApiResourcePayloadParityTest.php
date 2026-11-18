<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PinnedAuthorPostResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\ProfiledUserResource;
use Tests\Fixtures\Resources\ProfileResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Parity test between the exported resource components and rendered payloads.
 *
 * Whatever fields a request selects, the payload must validate against the
 * component its resource exports.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceSchemaBuilder::class)]
final class OpenApiResourcePayloadParityTest extends TestCase
{
    use ValidatesOpenApiDocuments;

    /** @var array<string, array{class-string<\Illuminate\Database\Eloquent\Model>, class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>}> The model and resource rendered per route type */
    private const array RENDERED = [
        'users'         => [User::class, UserResource::class],
        'posts'         => [Post::class, PostResource::class],
        'tags'          => [Tag::class, TagResource::class],
        'organizations' => [Organization::class, OrganizationResource::class],
        'pinned'        => [Post::class, PinnedAuthorPostResource::class],
    ];

    /**
     * Set up each test with the documented resources, a route rendering each
     * and one related record of every kind.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        config()->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
        ]);

        Route::get('users/{user}', [PathFixtureController::class, 'show']);
        Route::middleware(ParseApiQuery::class)->get('payloads/{type}', static function (string $type): ApiResource {

            [$model, $resource] = self::RENDERED[$type];

            return new $resource($model::query()->firstOrFail(), true);
        });

        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user         = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'status' => 'active', 'organization_id' => $organization->id]);
        $post         = Post::create(['user_id' => $user->id, 'title' => 'First', 'body' => 'Body', 'published' => true]);

        $post->tags()->attach(Tag::create(['name' => 'news']));
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
     * Provide each documented resource with its component and one field.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function resourceProvider(): iterable
    {
        yield 'users' => ['users', 'User', 'name'];
        yield 'posts' => ['posts', 'Post', 'title'];
        yield 'tags' => ['tags', 'Tag', 'name'];
        yield 'organizations' => ['organizations', 'Organization', 'slug'];
    }

    /**
     * Test that the default, a sparse and the full selection of a resource each
     * validate against its component.
     *
     * @param  string  $type
     * @param  string  $component
     * @param  string  $field
     * @return void
     */
    #[DataProvider('resourceProvider')]
    public function testEverySelectionValidatesAgainstTheComponent(string $type, string $component, string $field): void
    {
        $document = $this->exportedDocument();

        foreach (['default' => [], 'sparse' => [$type => $field], 'all' => [$type => ':all']] as $selection => $fields) {

            $payload = $this->getJson('/payloads/' . $type . '?' . http_build_query(['fields' => $fields]))->assertOk()->json('data');
            $result  = $this->validateAgainstExported($document, '/components/schemas/' . $component, $payload);

            self::assertTrue($result->isValid(), sprintf('The %s %s payload breaks its component: %s', $selection, $type, $this->formatErrors($result)));
        }
    }

    /**
     * Test that a relation pinning its fields embeds a payload that validates
     * against the related component.
     *
     * @return void
     */
    public function testAPinnedRelationValidatesAgainstTheRelatedComponent(): void
    {
        $author = $this->getJson('/payloads/pinned')->assertOk()->json('data.user');

        self::assertIsArray($author);
        self::assertEqualsCanonicalizing(['_type', 'id', 'name'], array_keys($author));

        $result = $this->validateAgainstExported($this->exportedDocument(), '/components/schemas/User', $author);

        self::assertTrue($result->isValid(), $this->formatErrors($result));
    }

    /**
     * Test that to-one relations whose related rows are absent render null, and
     * that the payload validates against the component.
     *
     * @return void
     */
    public function testAbsentToOneRelationsRenderNullAndValidate(): void
    {
        $user    = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
        $payload = $this->renderProfiledUser($user);

        self::assertNull($payload['organization']);
        self::assertNull($payload['profile']);
        self::assertNull($payload['organization_name']);

        $result = $this->validateAgainstExported($this->exportedDocument(), '/components/schemas/ProfiledUser', $payload);

        self::assertTrue($result->isValid(), $this->formatErrors($result));
    }

    /**
     * Test that populated to-one relations, and a relation transformed to a
     * declared string, validate against the component.
     *
     * @return void
     */
    public function testPopulatedToOneRelationsValidateAgainstTheComponent(): void
    {
        $user = User::query()->firstOrFail();

        Profile::create(['user_id' => $user->id, 'bio' => 'Mathematician']);

        $payload  = $this->renderProfiledUser($user);
        $document = $this->exportedDocument();
        $result   = $this->validateAgainstExported($document, '/components/schemas/ProfiledUser', $payload);

        self::assertSame('Acme', $payload['organization']['name']);
        self::assertSame('Mathematician', $payload['profile']['bio']);
        self::assertSame('Acme', $payload['organization_name']);
        self::assertTrue($result->isValid(), $this->formatErrors($result));
        $this->assertValidDocument($document);
    }

    /**
     * Render a user through the resource embedding its to-one relations, with
     * that resource documented in place of the user resource.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return array<string, mixed>
     */
    private function renderProfiledUser(User $user): array
    {
        config()->set('api-toolkit.resources.resource_map', [
            User::class         => ProfiledUserResource::class,
            Organization::class => OrganizationResource::class,
            Profile::class      => ProfileResource::class,
        ]);

        Route::middleware(ParseApiQuery::class)->get(
            'profiled-users/{user}',
            static fn (string $user): ApiResource => new ProfiledUserResource(User::query()->findOrFail($user), true),
        );

        /** @var array<string, mixed> */
        return $this->getJson('/profiled-users/' . $user->id)->assertOk()->json('data');
    }

    /**
     * Export the document the components are read from.
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
