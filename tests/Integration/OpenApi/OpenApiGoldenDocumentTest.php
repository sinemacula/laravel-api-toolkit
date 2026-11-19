<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Concerns\WritesDocsDirectories;
use Tests\Fixtures\Controllers\ArticleController;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\InternalOnlyTagController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\UserPostController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TaggedArticleResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Pins the whole document a representative application exports, so any change
 * to what consumers commit and generate clients from is reviewed.
 *
 * Run with API_TOOLKIT_UPDATE_GOLDEN=1 to rewrite the golden documents.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiCommand::class)]
#[CoversClass(OpenApiAssembler::class)]
final class OpenApiGoldenDocumentTest extends TestCase
{
    use ValidatesOpenApiDocuments, WritesDocsDirectories;

    /** @var string The directory holding the golden document of each audience */
    private const string GOLDEN = __DIR__ . '/../../Fixtures/OpenApi/Golden';

    /** @var array<class-string<\Illuminate\Database\Eloquent\Model>, class-string> The resources the routes render */
    private const array RESOURCE_MAP = [
        Article::class      => TaggedArticleResource::class,
        User::class         => UserResource::class,
        Organization::class => OrganizationResource::class,
        Post::class         => PostResource::class,
        Tag::class          => TagResource::class,
    ];

    /** @var list<string> The exported audiences */
    private const array AUDIENCES = ['public', 'internal'];

    /** @var string The output path, less its extension, each audience's document is named from */
    private string $output;

    /**
     * Configure and route the representative application.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auth.guards.api', ['driver' => 'jwt']);
        Config::set('api-toolkit.resources.resource_map', self::RESOURCE_MAP);
        Config::set('api-toolkit.openapi.operation_ids', true);
        Config::set('api-toolkit.openapi.info', ['title' => 'Example API', 'version' => '1.0.0']);
        Config::set('api-toolkit.openapi.servers', [['url' => 'https://api.example.com', 'description' => 'Production']]);
        Config::set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => ['info' => ['title' => 'Example Internal API'], 'servers' => [['url' => 'https://internal.example.com']]],
        ]);

        $this->useDocsDirectory([
            '01-introduction.md'           => "# Introduction\n\nEvery request is authenticated with a bearer token.\n",
            'operations/articles.store.md' => "Creates an article by the caller.\n",
            'tags/TaggedArticle.md'        => "Articles and the tags they carry.\n",
        ]);

        Route::middleware([ParseApiQuery::class, 'auth:api', SubstituteBindings::class])->group(static function (): void {
            Route::apiResource('articles', ArticleController::class);
            Route::apiResource('users', PathFixtureController::class);
            Route::apiResource('users.posts', UserPostController::class)->only(['index', 'show']);
            Route::get('tags', [InternalOnlyTagController::class, 'index'])->name('tags.index');
        });

        $this->output = sys_get_temp_dir() . '/api-toolkit-golden-' . uniqid('', true);
    }

    /**
     * Remove the exported documents.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::AUDIENCES as $audience) {
            @unlink($this->pathOf($audience));
        }

        parent::tearDown();
    }

    /**
     * Test that each audience's export is valid OpenAPI and matches its golden
     * document byte for byte.
     *
     * @return void
     */
    public function testExportMatchesTheGoldenDocuments(): void
    {
        foreach ($this->exportDocuments() as $audience => $document) {

            $result = $this->validateJson($document);

            self::assertTrue($result->isValid(), sprintf('The [%s] export is not valid OpenAPI 3.1: %s', $audience, $this->formatErrors($result)));

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);

            $this->assertReferencesResolve($decoded);

            $golden = self::GOLDEN . '/' . $audience . '.json';

            if (getenv('API_TOOLKIT_UPDATE_GOLDEN') === '1') {
                file_put_contents($golden, $document);
            }

            self::assertStringEqualsFile($golden, $document, sprintf('The [%s] export differs from its golden document.', $audience));
        }
    }

    /**
     * Test that exporting twice in one process writes identical documents.
     *
     * @return void
     */
    public function testConsecutiveExportsAreIdentical(): void
    {
        self::assertSame($this->exportDocuments(), $this->exportDocuments());
    }

    /**
     * Test that the order of the resource map does not change the documents.
     *
     * @return void
     */
    public function testResourceMapOrderDoesNotChangeTheDocuments(): void
    {
        $documents = $this->exportDocuments();

        Config::set('api-toolkit.resources.resource_map', array_reverse(self::RESOURCE_MAP, true));

        self::assertSame($documents, $this->exportDocuments());
    }

    /**
     * Export every audience through the command and read back what it wrote.
     *
     * @return array<string, string>
     */
    private function exportDocuments(): array
    {
        $command = $this->artisan('api-toolkit:export-openapi', ['--all' => true, '--output' => $this->output . '.json']);

        assert($command instanceof PendingCommand);

        $command->assertSuccessful()->run();

        $documents = [];

        foreach (self::AUDIENCES as $audience) {

            $contents = file_get_contents($this->pathOf($audience));

            self::assertIsString($contents);

            $documents[$audience] = $contents;
        }

        return $documents;
    }

    /**
     * Resolve the path the command writes the audience's document to.
     *
     * @param  string  $audience
     * @return string
     */
    private function pathOf(string $audience): string
    {
        return $this->output . '.' . $audience . '.json';
    }
}
