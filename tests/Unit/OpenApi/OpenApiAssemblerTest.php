<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the OpenApiAssembler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiAssembler::class)]
final class OpenApiAssemblerTest extends TestCase
{
    /**
     * Test that the assembled document declares a 3.1.x OpenAPI version.
     *
     * @return void
     */
    public function testDocumentDeclaresOpenApiThreeOne(): void
    {
        $document = $this->assemble();

        self::assertArrayHasKey('openapi', $document);
        self::assertStringStartsWith('3.1', $document['openapi']);
    }

    /**
     * Test that the document carries a minimal info block.
     *
     * @return void
     */
    public function testDocumentCarriesAMinimalInfoBlock(): void
    {
        $document = $this->assemble();

        self::assertArrayHasKey('title', $document['info']);
        self::assertArrayHasKey('version', $document['info']);
    }

    /**
     * Test that the assembled document's info block reflects the per-audience
     * info configured for the resolved audience.
     *
     * @return void
     */
    public function testInfoReflectsPerAudienceConfiguration(): void
    {
        $this->config()->set('api-toolkit.openapi.default_audience', 'internal');
        $this->config()->set('api-toolkit.openapi.audiences', [
            'internal' => [
                'info' => [
                    'title'       => 'Internal API',
                    'version'     => '3.4.5',
                    'description' => 'For staff only.',
                ],
            ],
        ]);

        $info = $this->assemble()['info'];

        self::assertSame('Internal API', $info['title']);
        self::assertSame('3.4.5', $info['version']);
        self::assertSame('For staff only.', $info['description']);
    }

    /**
     * Test that the assembled document's info block falls back to the top-level
     * info default when the audience declares none.
     *
     * @return void
     */
    public function testInfoFallsBackToTopLevelDefault(): void
    {
        $this->config()->set('api-toolkit.openapi.info', [
            'title'   => 'Acme API',
            'version' => '2.0.0',
        ]);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        $info = $this->assemble()['info'];

        self::assertSame('Acme API', $info['title']);
        self::assertSame('2.0.0', $info['version']);
        self::assertArrayNotHasKey('description', $info);
    }

    /**
     * Test that the document declares no path operations, so the package never
     * crosses the components/paths seam.
     *
     * @return void
     */
    public function testDocumentDeclaresNoPaths(): void
    {
        $document = $this->assembleEmptyAudience();

        self::assertArrayHasKey('paths', $document);
        self::assertSame([], (array) $document['paths']);
    }

    /**
     * Test that the paths value serialises to an empty JSON object rather than
     * an array, keeping the document schema-valid.
     *
     * @return void
     */
    public function testPathsSerialiseToAnEmptyObject(): void
    {
        $document = $this->assembleEmptyAudience();

        self::assertSame('{}', json_encode($document['paths']));
    }

    /**
     * Test that the components block is populated with schemas, parameters, and
     * responses.
     *
     * @return void
     */
    public function testComponentsBlockIsPopulated(): void
    {
        $components = $this->assemble()['components'];

        self::assertNotEmpty($components['schemas']);
        self::assertNotEmpty($components['parameters']);
        self::assertNotEmpty($components['responses']);
    }

    /**
     * Test that the resource schemas reachable from the audience's paths appear
     * under components.schemas alongside the shared error-envelope schema.
     *
     * @return void
     */
    public function testResourceAndEnvelopeSchemasArePresent(): void
    {
        $this->registerUserRoutes();

        $schemas = $this->assemble()['components']['schemas'];

        self::assertArrayHasKey('User', $schemas);
        self::assertArrayHasKey('Organization', $schemas);
        self::assertArrayHasKey(ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME, $schemas);
    }

    /**
     * Test that the shared response-envelope pagination schemas are emitted
     * under components.schemas.
     *
     * @return void
     */
    public function testEnvelopeSchemasArePresent(): void
    {
        $schemas = $this->assemble()['components']['schemas'];

        self::assertArrayHasKey(EnvelopeBuilder::PAGINATION_META_SCHEMA_NAME, $schemas);
        self::assertArrayHasKey(EnvelopeBuilder::PAGINATION_LINKS_SCHEMA_NAME, $schemas);
        self::assertArrayHasKey(EnvelopeBuilder::CURSOR_PAGINATION_META_SCHEMA_NAME, $schemas);
        self::assertArrayHasKey(EnvelopeBuilder::CURSOR_PAGINATION_LINKS_SCHEMA_NAME, $schemas);
    }

    /**
     * Test that an index operation offers both pagination shapes as a oneOf of
     * the length-aware and cursor collection envelopes, and that the cursor
     * envelope's schemas survive the reachability filtering now a path
     * references them.
     *
     * @return void
     */
    public function testIndexOffersBothPaginationShapesAndKeepsCursorSchemas(): void
    {
        $this->registerUserRoutes();

        $document = $this->assemble();
        $schema   = $document['paths']['/users']['get']['responses']['200']['content']['application/json']['schema'];
        $envelope = new EnvelopeBuilder;

        self::assertSame(
            [
                'oneOf' => [
                    $envelope->collectionEnvelope('#/components/schemas/User'),
                    $envelope->cursorCollectionEnvelope('#/components/schemas/User'),
                ],
            ],
            $schema,
        );

        $schemas = $document['components']['schemas'];

        self::assertArrayHasKey(EnvelopeBuilder::CURSOR_PAGINATION_META_SCHEMA_NAME, $schemas);
        self::assertArrayHasKey(EnvelopeBuilder::CURSOR_PAGINATION_LINKS_SCHEMA_NAME, $schemas);
    }

    /**
     * Test that components.securitySchemes carries exactly the distinct schemes
     * referenced by the assembled paths, each with its correct definition.
     *
     * @return void
     */
    public function testSecuritySchemesReflectReferencedSchemes(): void
    {
        $this->configureGuards();

        $router = $this->router();
        $router->get('users', [PathFixtureController::class, 'index'])->middleware('auth:api');
        $router->get('admins', [PathFixtureController::class, 'index'])->middleware('auth:admin');
        $router->get('sessions', [PathFixtureController::class, 'index'])->middleware('auth:web');

        $schemes = $this->assemble()['components']['securitySchemes'];

        self::assertSame(['bearerAuth', 'basicAuth', 'cookieAuth'], array_keys($schemes));
        self::assertSame(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], $schemes['bearerAuth']);
        self::assertSame(['type' => 'http', 'scheme' => 'basic'], $schemes['basicAuth']);
        self::assertSame('apiKey', $schemes['cookieAuth']['type']);
        self::assertSame('cookie', $schemes['cookieAuth']['in']);
    }

    /**
     * Test that a document whose paths reference no auth scheme omits the
     * securitySchemes key entirely, keeping the components block valid.
     *
     * @return void
     */
    public function testSecuritySchemesOmittedWhenNoRouteIsAuthenticated(): void
    {
        $this->registerUserRoutes();

        self::assertArrayNotHasKey('securitySchemes', $this->assemble()['components']);
    }

    /**
     * Test that the reusable total-count header is emitted under
     * components.headers.
     *
     * @return void
     */
    public function testTotalCountHeaderIsPresent(): void
    {
        $headers = $this->assemble()['components']['headers'];

        self::assertArrayHasKey(EnvelopeBuilder::TOTAL_COUNT_HEADER_NAME, $headers);
    }

    /**
     * Test that the shared query-parameter vocabulary is emitted once under
     * components.parameters.
     *
     * @return void
     */
    public function testSharedParametersAreEmittedOnce(): void
    {
        $parameters = $this->assemble()['components']['parameters'];

        self::assertArrayHasKey('Filter', $parameters);
        self::assertArrayHasKey('Fields', $parameters);
        self::assertArrayHasKey('Order', $parameters);
    }

    /**
     * Test that each error code yields a reusable response under
     * components.responses.
     *
     * @return void
     */
    public function testErrorResponsesArePresent(): void
    {
        $responses = $this->assemble()['components']['responses'];

        self::assertArrayHasKey('ErrorResponse10103', $responses);
    }

    /**
     * Test that the assembled document serialises to valid JSON, proving it is
     * a plain associative array ready for the output port.
     *
     * @return void
     */
    public function testDocumentSerialisesToJson(): void
    {
        $json = json_encode($this->assemble(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        self::assertIsString($json);
        self::assertStringContainsString('"openapi"', $json);
    }

    /**
     * Test that a non-empty audience argument is honoured verbatim rather than
     * being coerced to the configured default audience.
     *
     * @return void
     */
    public function testExplicitAudienceIsHonouredOverDefault(): void
    {
        $this->config()->set('api-toolkit.openapi.default_audience', 'public');
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public'   => ['info' => ['title' => 'Public API', 'version' => '1.0.0']],
            'internal' => ['info' => ['title' => 'Internal API', 'version' => '9.9.9']],
        ]);

        $info = $this->makeAssembler()->assemble('internal')['info'];

        self::assertSame('Internal API', $info['title']);
        self::assertSame('9.9.9', $info['version']);
    }

    /**
     * Test that an operation naming two single-scheme security requirements
     * contributes both schemes to the components block, not just one.
     *
     * @return void
     */
    public function testSecuritySchemesAccumulateAcrossRequirements(): void
    {
        $this->configureGuards();

        $this->router()->get('reports', [PathFixtureController::class, 'index'])->middleware('auth:api,web');

        $schemes = $this->assemble()['components']['securitySchemes'];

        self::assertArrayHasKey('bearerAuth', $schemes);
        self::assertArrayHasKey('cookieAuth', $schemes);
    }

    /**
     * Test that the shared error-envelope schema is retained even when the
     * audience documents no paths that could reference it.
     *
     * @return void
     */
    public function testSharedErrorEnvelopeSurvivesWithoutPaths(): void
    {
        $schemas = $this->assembleEmptyAudience()['components']['schemas'];

        self::assertArrayHasKey(ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME, $schemas);
    }

    /**
     * Assemble a document from real builders backed by a stubbed catalogue and
     * a real resolver against the live test schema.
     *
     * @return array<string, mixed>
     */
    private function assemble(): array
    {
        return $this->makeAssembler()->assemble();
    }

    /**
     * Assemble a document for an allowlist audience no route opts into, so the
     * paths object is genuinely empty even with the framework's default routes
     * registered.
     *
     * @return array<string, mixed>
     */
    private function assembleEmptyAudience(): array
    {
        $this->config()->set('api-toolkit.openapi.audiences', [
            'partner' => ['posture' => 'allowlist'],
        ]);

        return $this->makeAssembler()->assemble('partner');
    }

    /**
     * Build an assembler from the three real builders.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\OpenApiAssembler
     */
    private function makeAssembler(): OpenApiAssembler
    {
        $catalogue = $this->makeCatalogue();

        assert($this->app !== null);

        return new OpenApiAssembler(
            new ResourceSchemaBuilder($catalogue, $this->resolver(), $this->app->make(SchemaIntrospector::class)),
            new QueryParameterBuilder($catalogue),
            new ErrorResponseBuilder($catalogue),
            new PathBuilder(
                $this->router(),
                $catalogue,
                new AudienceResolver,
                new DocumentableRouteFilter,
                new EnvelopeBuilder,
                new TagResolver,
                new ResponseSchemaResolver($catalogue, new EnvelopeBuilder),
                new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder)),
                new SecuritySchemeResolver(new SecuritySchemeMapper),
            ),
            new SecuritySchemeResolver(new SecuritySchemeMapper),
        );
    }

    /**
     * Register a full REST route set for the user resource so its schema is
     * reachable from the assembled paths.
     *
     * @return void
     */
    private function registerUserRoutes(): void
    {
        $router = $this->router();

        $router->get('users', [PathFixtureController::class, 'index']);
        $router->post('users', [PathFixtureController::class, 'store']);
        $router->get('users/{user}', [PathFixtureController::class, 'show']);
        $router->match(['PUT', 'PATCH'], 'users/{user}', [PathFixtureController::class, 'update']);
        $router->delete('users/{user}', [PathFixtureController::class, 'destroy']);
    }

    /**
     * Configure the auth guards the security schemes are derived from.
     *
     * @return void
     */
    private function configureGuards(): void
    {
        $config = $this->config();

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.api', ['driver' => 'jwt']);
        $config->set('auth.guards.web', ['driver' => 'session']);
        $config->set('auth.guards.admin', ['driver' => 'basic']);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function config(): ConfigRepository
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->app->make('config');
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }

    /**
     * Build a stubbed catalogue exposing a resource map, the operator
     * vocabulary, and a single error descriptor.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue
     */
    private function makeCatalogue(): MetadataCatalogue
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
        ]);
        $catalogue->method('getOperatorTokens')->willReturn(['$eq', '$neq']);
        $catalogue->method('getStructuralOperators')->willReturn(['$and', '$or']);
        $catalogue->method('getErrorCatalogue')->willReturn([
            new ErrorDescriptor(code: 10103, httpStatus: 404, title: 'Not Found', detail: 'Missing.'),
        ]);

        return $catalogue;
    }

    /**
     * Build a real field-type resolver against the container-bound
     * introspector.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver
     */
    private function resolver(): FieldTypeResolver
    {
        assert($this->app !== null);

        return new FieldTypeResolver($this->app->make(SchemaIntrospector::class), new ColumnTypeMapper);
    }
}
