<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\BillingController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Full-facet interaction test for a single assembled endpoint.
 *
 * A facet dropped or mis-merged during assembly must fail one of these
 * assertions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiComponents::class)]
#[CoversClass(OpenApiAssembler::class)]
#[CoversClass(PathBuilder::class)]
final class OpenApiFullEndpointTest extends TestCase
{
    use ValidatesOpenApiDocuments;

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
        $this->configureGuards();
        $this->registerBillingRoute();
    }

    /**
     * Test that the assembled POST operation carries every facet at once.
     *
     * @return void
     */
    public function testAssembledOperationCarriesEveryFacetSimultaneously(): void
    {
        $document  = $this->export()->document;
        $operation = $document['paths']['/billing']['post'];

        $body   = $operation['requestBody'];
        $schema = $body['content']['application/json']['schema'];

        self::assertTrue($body['required']);
        self::assertSame(['type' => 'string', 'maxLength' => 255], $schema['properties']['title']);
        self::assertSame(['title', 'email', 'password', 'password_confirmation'], $schema['required']);

        self::assertSame(
            ['$ref' => '#/components/schemas/User'],
            $operation['responses']['201']['content']['application/json']['schema']['properties']['data'],
        );

        self::assertSame([['bearerAuth' => []]], $operation['security']);

        self::assertSame(
            ['$ref' => '#/components/schemas/Error10108'],
            $operation['responses'][409]['content']['application/json']['schema'],
        );
        self::assertIsArray($operation['responses']);
        self::assertSame(
            [201, 401, 403, 409, 422, 500],
            array_keys($operation['responses']),
        );

        self::assertSame(['Billing'], $operation['tags']);

        $this->assertValidDocument($document);
    }

    /**
     * Test that the write operation is both authenticated and body-bearing.
     *
     * @return void
     */
    public function testWriteOperationIsBothAuthenticatedAndBodyBearing(): void
    {
        $operation = $this->export()->document['paths']['/billing']['post'];

        self::assertSame([['bearerAuth' => []]], $operation['security']);
        self::assertTrue($operation['requestBody']['required']);
        self::assertIsArray($operation['requestBody']['content']['application/json']['schema']['properties']);
        self::assertArrayHasKey('title', $operation['requestBody']['content']['application/json']['schema']['properties']);
    }

    /**
     * Test that the referenced security scheme is emitted as a component.
     *
     * @return void
     */
    public function testSecuritySchemeComponentBacksTheOperationRequirement(): void
    {
        $document = $this->export()->document;

        self::assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $document['components']['securitySchemes']['bearerAuth'],
        );
    }

    /**
     * Test that the class-level #[Tag] overrides the resource-derived tag.
     *
     * @return void
     */
    public function testCustomTagSurvivesToExportedDocument(): void
    {
        $operation = $this->export()->document['paths']['/billing']['post'];

        self::assertSame(['Billing'], $operation['tags']);
    }

    /**
     * Configure the auth guards the security derivation reads drivers from.
     *
     * @return void
     */
    private function configureGuards(): void
    {
        $config = $this->getConfig();

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.api', ['driver' => 'jwt']);
    }

    /**
     * Register the authenticated, body-bearing billing write route.
     *
     * @return void
     */
    private function registerBillingRoute(): void
    {
        $this->router()->post('billing', [BillingController::class, 'store'])->middleware('auth:api');
    }

    /**
     * Run the export use case against the container-resolved graph.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(): ExportResult
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->makeApplication()->make(ExportOpenApiComponents::class);

        return $exporter->export();
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
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        return $this->makeApplication()->make(Router::class);
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
