<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Concerns\WritesDocsDirectories;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\InternalOnlyTagController;
use Tests\Fixtures\OpenApi\PartnerOrganizationController;
use Tests\Fixtures\OpenApi\PathOrganizationController;
use Tests\Fixtures\OpenApi\PathTaggedController;
use Tests\Fixtures\OpenApi\PathUnmappedController;
use Tests\Fixtures\OpenApi\UndocumentedOrganizationController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\QueryableTagResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\TraversingPostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end audience-selection test for the OpenAPI exporter.
 *
 * Every produced document is also asserted valid OpenAPI 3.1.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiAssembler::class)]
#[CoversClass(PathBuilder::class)]
#[CoversClass(AudienceResolver::class)]
#[CoversClass(AudienceConfiguration::class)]
#[CoversClass(ReachableSchemaResolver::class)]
#[CoversClass(ResourceSchemaBuilder::class)]
final class OpenApiAudienceExportTest extends TestCase
{
    use ValidatesOpenApiDocuments, WritesDocsDirectories;

    /** @var string|null The temporary docs directory to clean up. */
    private ?string $docsDir = null;

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        $this->registerResourceMap();
    }

    /**
     * Tear down each test, removing any temporary docs directory.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->docsDir !== null) {
            $this->removeDirectory($this->docsDir);
        }

        $this->docsDir = null;

        parent::tearDown();
    }

    /**
     * Test that an allowlist opt-in documents the route for that audience only.
     *
     * @return void
     */
    public function testAllowlistOptInDocumentsRouteAndSchemaInPartnerOnly(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'  => ['posture' => 'allowlist'],
            'partner' => ['posture' => 'allowlist'],
        ]);

        $this->router()->get('organizations', [PartnerOrganizationController::class, 'index']);

        $partner = $this->export('partner')->document;

        self::assertIsArray($partner['paths']);
        self::assertArrayHasKey('/organizations', $partner['paths']);
        self::assertSame(
            ['type' => 'string', 'const' => 'organizations'],
            $partner['components']['schemas']['Organization']['properties']['_type'],
        );

        // The default (public) audience is an allowlist naming nothing
        $default = $this->export()->document;

        self::assertSame('{}', json_encode($default['paths']));
        self::assertIsArray($default['components']['schemas']);
        self::assertArrayNotHasKey('Organization', $default['components']['schemas']);

        $this->assertValidDocument($partner);
        $this->assertValidDocument($default);
    }

    /**
     * Test that an Undocumented controller is dropped from every audience.
     *
     * @return void
     */
    public function testUndocumentedControllerDroppedFromEveryAudiencePathsAndSchemas(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()->get('organizations', [UndocumentedOrganizationController::class, 'index']);
        $this->router()->get('widgets', [PathTaggedController::class, 'index']);

        foreach (['public', 'internal'] as $audience) {

            $document = $this->export($audience)->document;

            self::assertIsArray($document['paths']);
            self::assertArrayHasKey('/widgets', $document['paths']);
            self::assertArrayNotHasKey('/organizations', $document['paths']);
            self::assertIsArray($document['components']['schemas']);
            self::assertArrayNotHasKey('Organization', $document['components']['schemas']);

            $this->assertValidDocument($document);
        }
    }

    /**
     * Test that the internal-only pattern documents in the internal audience.
     *
     * @return void
     */
    public function testInternalOnlyPatternDocumentsRouteAndSchemaInInternalOnly(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()->get('tags', [InternalOnlyTagController::class, 'index']);

        $internal = $this->export('internal')->document;

        self::assertIsArray($internal['paths']);
        self::assertArrayHasKey('/tags', $internal['paths']);
        self::assertSame(
            ['type' => 'string', 'const' => 'tags'],
            $internal['components']['schemas']['Tag']['properties']['_type'],
        );

        $public = $this->export('public')->document;

        // The paths object is emitted empty here, so cast it before asserting
        self::assertArrayNotHasKey('/tags', (array) $public['paths']);
        self::assertIsArray($public['components']['schemas']);
        self::assertArrayNotHasKey('Tag', $public['components']['schemas']);

        self::assertArrayNotHasKey('/tags', (array) $this->export()->document['paths']);

        $this->assertValidDocument($internal);
        $this->assertValidDocument($public);
    }

    /**
     * Test that notDocumentedIn excludes a route from the named audience only.
     *
     * @return void
     */
    public function testRouteMacroNotDocumentedInExcludesFromThatAudienceOnly(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()
            ->get('organizations', [PathOrganizationController::class, 'index'])
            ->notDocumentedIn('public'); // @phpstan-ignore method.notFound

        $public = $this->export('public')->document;

        self::assertArrayNotHasKey('/organizations', (array) $public['paths']);

        $internal = $this->export('internal')->document;

        self::assertIsArray($internal['paths']);
        self::assertArrayHasKey('/organizations', $internal['paths']);

        $this->assertValidDocument($public);
        $this->assertValidDocument($internal);
    }

    /**
     * Test that an internal-only resource never leaks into public schemas.
     *
     * @return void
     */
    public function testInternalOnlyResourceDoesNotLeakIntoPublicSchemas(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()->get('organizations', [PathOrganizationController::class, 'index']);
        $this->router()->get('tags', [InternalOnlyTagController::class, 'index']);

        $public = $this->export('public')->document;

        self::assertIsArray($public['paths']);
        self::assertArrayHasKey('/organizations', $public['paths']);
        self::assertArrayNotHasKey('/tags', $public['paths']);
        self::assertIsArray($public['components']['schemas']);
        self::assertArrayHasKey('Organization', $public['components']['schemas']);
        self::assertArrayNotHasKey('Tag', $public['components']['schemas']);

        // The internal audience shows the resource is reachable, not unbuilt
        $internal = $this->export('internal')->document;

        self::assertIsArray($internal['components']['schemas']);
        self::assertArrayHasKey('Tag', $internal['components']['schemas']);

        $this->assertValidDocument($public);
        $this->assertValidDocument($internal);
    }

    /**
     * Test that an internal-only query surface stays out of public documents.
     *
     * The manual is assembled first, so info.description is checked too.
     *
     * @return void
     */
    public function testInternalOnlyQuerySurfaceDoesNotLeakIntoPublicDocument(): void
    {
        $this->registerInternalOnlySurface();
        $this->assembleManual();

        $internal = $this->export('internal')->document;

        self::assertSame(
            [
                'filter' => ['key' => 'name', 'capability' => 'exact', 'operators' => ['$eq', '$in', '$null', '$notNull']],
                'sort'   => ['key' => 'name', 'indexed' => true],
                'search' => ['key' => 'name', 'strategy' => 'prefix'],
            ],
            $internal['components']['schemas']['QueryableTag']['properties']['name']['x-query-surface'],
        );

        $public = $this->export('public')->document;

        self::assertIsArray($public['components']['schemas']);
        self::assertArrayNotHasKey('QueryableTag', $public['components']['schemas']);

        // The surface rides the property, not the global parameter components
        self::assertStringNotContainsString('x-query-surface', json_encode($public, JSON_THROW_ON_ERROR));

        // The generated reference could carry it too, so scan the whole thing
        self::assertStringNotContainsString('QueryableTag', json_encode($public, JSON_THROW_ON_ERROR));

        $this->assertValidDocument($internal);
        $this->assertValidDocument($public);
    }

    /**
     * Test that an internal resource's filter document stays out of a public
     * document until a public index filters into it.
     *
     * @return void
     */
    public function testAnInternalResourcesFilterDocumentReachesOnlyTheAudiencesThatFilterIntoIt(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', ['public' => [], 'internal' => []]);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [Post::class => TraversingPostResource::class, Tag::class => QueryableTagResource::class]);

        $this->router()->get('tags', [InternalOnlyTagController::class, 'index']);

        self::assertArrayHasKey('QueryableTagFilters', $this->schemasOf($this->export('internal')->document));
        self::assertArrayNotHasKey('QueryableTagFilters', $this->schemasOf($this->export('public')->document));

        $this->router()->get('posts', [PathUnmappedController::class, 'index']);

        $public  = $this->export('public')->document;
        $schemas = $this->schemasOf($public);

        self::assertSame(['/posts'], array_keys((array) $public['paths']));
        self::assertSame('#/components/schemas/QueryableTagFilters', $schemas['TraversingPostFilters']['properties']['tags']['$ref'] ?? null);
        self::assertArrayHasKey('QueryableTagFilters', $schemas);

        $this->assertValidDocument($public);
    }

    /**
     * Test that the query surface reference reaches the documenting audience.
     *
     * @return void
     */
    public function testTheQuerySurfaceReferenceReachesTheAudienceThatDocumentsIt(): void
    {
        $this->registerInternalOnlySurface();
        $this->assembleManual();

        $description = $this->export('internal')->document['info']['description'] ?? null;

        self::assertIsString($description);
        self::assertStringContainsString('# Query Surface Reference', $description);
        self::assertStringContainsString('### QueryableTag', $description);

        $public = $this->export('public')->document['info']['description'] ?? null;

        self::assertIsString($public);
        self::assertStringContainsString('# Query Surface Reference', $public);
        self::assertStringContainsString('### Organization', $public);
    }

    /**
     * Test that an audience's operation and tag files replace the shared ones
     * for it alone, and an internal-only tag stays out of the public list.
     *
     * @return void
     */
    public function testAudienceOverridesOperationAndTagDescriptions(): void
    {
        $this->registerInternalOnlySurface();

        $this->getConfig()->set('api-toolkit.openapi.operation_ids', true);

        $this->useDocsDirectory([
            'operations/path-organization.index.md'                    => 'Lists the organizations.',
            'tags/Organization.md'                                     => 'Customer organizations.',
            'tags/QueryableTag.md'                                     => 'Labels.',
            'audiences/internal/operations/path-organization.index.md' => 'Lists every organization, archived ones included.',
            'audiences/internal/tags/Organization.md'                  => 'Every organization.',
        ]);

        $public   = $this->export('public')->document;
        $internal = $this->export('internal')->document;

        self::assertSame('Lists the organizations.', $public['paths']['/organizations']['get']['description']);
        self::assertSame([['name' => 'Organization', 'description' => 'Customer organizations.']], $public['tags']);
        self::assertSame('Lists every organization, archived ones included.', $internal['paths']['/organizations']['get']['description']);
        self::assertSame([['name' => 'Organization', 'description' => 'Every organization.'], ['name' => 'QueryableTag', 'description' => 'Labels.']], $internal['tags']);

        $this->assertValidDocument($public);
        $this->assertValidDocument($internal);
    }

    /**
     * Read the component schemas of an exported document.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, array<string, mixed>>
     */
    private function schemasOf(array $document): array
    {
        /** @var array<string, array<string, mixed>> */
        return $document['components']['schemas'];
    }

    /**
     * Declare public and internal audiences with an internal-only resource.
     *
     * @return void
     */
    private function registerInternalOnlySurface(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            Organization::class => OrganizationResource::class,
            Tag::class          => QueryableTagResource::class,
        ]);

        $this->router()->get('organizations', [PathOrganizationController::class, 'index']);
        $this->router()->get('tags', [InternalOnlyTagController::class, 'index']);
    }

    /**
     * Generate the reference sections into a temporary docs directory.
     *
     * @return void
     */
    private function assembleManual(): void
    {
        $this->docsDir = sys_get_temp_dir() . '/api-toolkit-docs-' . uniqid('', true);

        mkdir($this->docsDir);

        $this->getConfig()->set('api-toolkit.openapi.docs_path', $this->docsDir);

        $command = $this->artisan('api-toolkit:docs:generate');

        assert($command instanceof PendingCommand);

        $command->assertSuccessful();
    }

    /**
     * Remove a directory and everything beneath it.
     *
     * @param  string  $directory
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {

            if (is_dir($entry)) {
                $this->removeDirectory($entry);
                continue;
            }

            unlink($entry);
        }

        @rmdir($directory);
    }

    /**
     * Run the export use case against the container-resolved graph.
     *
     * @param  string|null  $audience
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(?string $audience = null): ExportResult
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->makeApplication()->make(ExportOpenApiComponents::class);

        return $exporter->export($audience);
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        return $this->makeApplication()->make(Router::class);
    }

    /**
     * Register the fixture resource map on the config repository.
     *
     * @return void
     */
    private function registerResourceMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
        ]);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->makeApplication()->make('config');
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function makeApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
