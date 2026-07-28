<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Enums\AppErrorCode;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\ColumnlessIntrospectionProvider;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathInvokableController;
use Tests\Fixtures\OpenApi\PathOrganizationController;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PathTagInternalController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end validity test for the OpenAPI exporter.
 *
 * Exports the document for the fixture resource registry against real routes
 * and proves the headline success metric: the emitted document validates
 * against the official OpenAPI 3.1 meta-schema (via opis/json-schema). It then
 * asserts the remaining oracles end to end -- 3.1.x version, populated
 * components, one schema per resource, full operator vocabulary in the filter
 * parameter, one response per error code, an empty paths object for an audience
 * with no route, created_at as a date-time string, an opaque compute field
 * flagged x-undocumented, and a regenerate-after-change diff -- plus the
 * per-audience paths, route scoping, reachable-only schema filtering, and the
 * command's --audience / --all behaviour.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiComponents::class)]
#[CoversClass(ExportOpenApiCommand::class)]
#[CoversClass(OpenApiAssembler::class)]
#[CoversClass(PathBuilder::class)]
#[CoversClass(ReachableSchemaResolver::class)]
#[CoversClass(AudienceConfiguration::class)]
final class OpenApiExporterValidityTest extends TestCase
{
    /** @var string The identifier under which the OpenAPI 3.1 meta-schema is registered. */
    private const string META_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    /** @var string The identifier of the JSON Schema 2020-12 dialect document. */
    private const string DIALECT_ID = 'https://json-schema.org/draft/2020-12/schema';

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
     * Test that the emitted document validates against the OpenAPI 3.1
     * meta-schema -- the 100% document-validity success metric.
     *
     * @return void
     */
    public function testEmittedDocumentValidatesAsOpenApiThreeOne(): void
    {
        $this->registerUserRoutes();

        $document = $this->export()->document;

        $result = $this->validateAgainstMetaSchema($document);

        self::assertTrue(
            $result->isValid(),
            'Emitted document is not valid OpenAPI 3.1: ' . $this->formatErrors($result),
        );
    }

    /**
     * Test that the emitted document declares a 3.1.x version and a populated
     * components block (AC-01/AC-09).
     *
     * @return void
     */
    public function testDocumentDeclaresThreeOneAndPopulatesComponents(): void
    {
        $document = $this->export()->document;

        self::assertStringStartsWith('3.1', $document['openapi']);

        $components = $document['components'];

        self::assertNotEmpty($components['schemas']);
        self::assertNotEmpty($components['parameters']);
        self::assertNotEmpty($components['responses']);
    }

    /**
     * Test that an audience with no documented route emits an empty paths
     * object rather than an array, keeping the document schema-valid.
     *
     * @return void
     */
    public function testAudienceWithNoDocumentedRoutesEmitsEmptyPathsObject(): void
    {
        // The partner audience is an allowlist and no route opts into it, so it
        // documents nothing even though the user routes are registered.
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'  => [],
            'partner' => ['posture' => 'allowlist'],
        ]);

        $document = $this->export('partner')->document;

        self::assertArrayHasKey('paths', $document);
        self::assertSame('{}', json_encode($document['paths']));

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with an empty paths object must validate as OpenAPI 3.1.',
        );
    }

    /**
     * Test that exactly one component schema is emitted per registered resource
     * (FR-3) and the summary count matches the registry size.
     *
     * @return void
     */
    public function testOneSchemaPerRegisteredResource(): void
    {
        $this->registerUserRoutes();

        $result = $this->export();

        self::assertSame(4, $result->resourceCount);

        $schemas = $result->document['components']['schemas'];

        foreach (['User', 'Organization', 'Post', 'Tag'] as $name) {
            self::assertArrayHasKey($name, $schemas);
        }
    }

    /**
     * Test that every registered operator and structural operator appears in
     * the emitted filter parameter (FR-4).
     *
     * @return void
     */
    public function testEveryOperatorAppearsInTheFilterParameter(): void
    {
        $document  = $this->export()->document;
        $operators = $document['components']['parameters']['Filter']['schema']['x-operators'];

        $expected = [
            '$eq', '$neq', '$gt', '$lt', '$ge', '$le', '$like', '$in',
            '$between', '$contains', '$null', '$notNull',
            '$and', '$or', '$has', '$hasnt',
        ];

        foreach ($expected as $operator) {
            self::assertContains($operator, $operators);
        }
    }

    /**
     * Test that the emitted document carries one error response per defined
     * error code, covering the toolkit's own codes and any application
     * exception discovered outside the toolkit.
     *
     * @return void
     */
    public function testOneResponsePerErrorCode(): void
    {
        $document   = $this->export()->document;
        $responses  = $document['components']['responses'];
        $errorCodes = ErrorCode::cases();

        // The toolkit's own codes plus the discovered application exception
        // fixture, which lives in a non-vendor PSR-4 root.
        self::assertCount(count($errorCodes) + 1, $responses);

        foreach ($errorCodes as $code) {
            self::assertArrayHasKey('ErrorResponse' . $code->getCode(), $responses);
        }

        self::assertArrayHasKey('ErrorResponse' . AppErrorCode::WIDGET_FAILURE->getCode(), $responses);
    }

    /**
     * Test that an un-annotated timestamp field is inferred as a date-time
     * string end to end (AC-07).
     *
     * @return void
     */
    public function testCreatedAtIsEmittedAsADateTimeString(): void
    {
        $this->registerUserRoutes();

        $document  = $this->export()->document;
        $createdAt = $document['components']['schemas']['User']['properties']['created_at'];

        // The column is nullable, so the 2020-12 nullable type-array form is
        // emitted; the date-time format is the AC-07 signal either way.
        self::assertContains('string', (array) $createdAt['type']);
        self::assertSame('date-time', $createdAt['format']);
    }

    /**
     * Test that an opaque compute field with no declaration carries the
     * x-undocumented marker and remains permissive (FR-8).
     *
     * @return void
     */
    public function testComputeFieldIsFlaggedUndocumented(): void
    {
        $this->registerUserRoutes();

        $document  = $this->export()->document;
        $fullLabel = $document['components']['schemas']['User']['properties']['full_label'];

        self::assertTrue($fullLabel['x-undocumented']);
        self::assertArrayNotHasKey('type', $fullLabel);
    }

    /**
     * Test that the shared response-envelope components (pagination schemas and
     * the total-count header) are emitted and the document stays 3.1-valid.
     *
     * @return void
     */
    public function testEnvelopeComponentsArePresentAndValid(): void
    {
        $document   = $this->export()->document;
        $components = $document['components'];

        foreach (['PaginationMeta', 'PaginationLinks', 'CursorPaginationMeta', 'CursorPaginationLinks'] as $name) {
            self::assertArrayHasKey($name, $components['schemas']);
        }

        self::assertArrayHasKey('Total-Count', $components['headers']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'Emitted document with envelope components is not valid OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that each resource schema carries a `_type` discriminator fixed to
     * the resource's registered type and lists it as required, end to end.
     *
     * @return void
     */
    public function testResourceSchemaCarriesTypeDiscriminator(): void
    {
        $this->registerUserRoutes();

        $user = $this->export()->document['components']['schemas']['User'];

        self::assertSame(['type' => 'string', 'const' => 'users'], $user['properties']['_type']);
        self::assertContains('_type', $user['required']);
    }

    /**
     * Test that a to-one relation property emits a concrete single reference
     * (cardinality-aware) rather than the conservative object-or-array shape.
     *
     * @return void
     */
    public function testToOneRelationEmitsSingleReference(): void
    {
        $this->registerUserRoutes();

        $document     = $this->export()->document;
        $organization = $document['components']['schemas']['User']['properties']['organization'];

        self::assertSame(['$ref' => '#/components/schemas/Organization'], $organization);
    }

    /**
     * Test that regenerating the document after a documented surface changes
     * produces a different component, and the new document stays 3.1-valid
     * (FR-2/AC-02).
     *
     * @return void
     */
    public function testRegenerateAfterChangeShowsADiff(): void
    {
        $this->registerUserRoutes();

        $before = $this->export()->document['components']['schemas']['Organization'];

        // Drop a resource from the registry and re-export: the previously
        // documented surface is no longer present in the regenerated document.
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        SchemaCompiler::clearCache();

        $after = $this->export()->document;

        self::assertArrayNotHasKey('Organization', $after['components']['schemas']);
        self::assertNotEmpty($before);

        self::assertTrue(
            $this->validateAgainstMetaSchema($after)->isValid(),
            'Regenerated document is not valid OpenAPI 3.1.',
        );
    }

    /**
     * Test that the artisan command writes a non-empty 3.1-valid document and
     * reports a non-zero resource count, exit 0 (AC-01).
     *
     * @return void
     */
    public function testCommandWritesAValidDocumentWithExitZero(): void
    {
        $this->registerUserRoutes();

        $path = sys_get_temp_dir() . '/api-toolkit-openapi-' . uniqid('', true) . '.json';

        try {

            $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--output' => $path]);

            self::assertSame(0, $exitCode);
            self::assertStringContainsString('Exported 4 resource schema(s)', Artisan::output());
            self::assertFileExists($path);

            $contents = file_get_contents($path);

            self::assertIsString($contents);
            self::assertNotSame('', $contents);

            self::assertTrue(
                $this->validateJson($contents)->isValid(),
                'Document written by the command is not valid OpenAPI 3.1.',
            );

        } finally {
            @unlink($path);
        }
    }

    /**
     * Test that the opis-adapted meta-schema still rejects invalid documents,
     * so the in-test compatibility transforms cannot silently hollow out the
     * headline validity signal if opis or the meta-schema changes.
     *
     * @return void
     */
    public function testAdaptedMetaSchemaStillRejectsInvalidDocuments(): void
    {
        $valid = $this->export()->document;

        self::assertTrue(
            $this->validateAgainstMetaSchema($valid)->isValid(),
            'The real emitted document must validate as OpenAPI 3.1.',
        );

        $threeZero            = $valid;
        $threeZero['openapi'] = '3.0.3';

        self::assertFalse(
            $this->validateAgainstMetaSchema($threeZero)->isValid(),
            'A 3.0 version string must be rejected by the 3.1 meta-schema.',
        );

        $missingInfo = $valid;
        unset($missingInfo['info']);

        self::assertFalse(
            $this->validateAgainstMetaSchema($missingInfo)->isValid(),
            'A document missing the required info object must be rejected.',
        );

        $malformedParameter                                       = $valid;
        $malformedParameter['components']['parameters']['Broken'] = ['description' => 'no name or in'];

        self::assertFalse(
            $this->validateAgainstMetaSchema($malformedParameter)->isValid(),
            'A parameter missing its required name/in must be rejected.',
        );
    }

    /**
     * Test that registered routes surface as path operations with the correct
     * REST responses for the exported audience, end to end.
     *
     * @return void
     */
    public function testRoutesSurfaceAsPathOperationsForAudience(): void
    {
        $this->registerUserRoutes();

        $document = $this->export()->document;
        $paths    = $document['paths'];

        self::assertSame(['get', 'post'], array_keys($paths['/users']));
        self::assertSame(['get', 'put', 'patch', 'delete'], array_keys($paths['/users/{user}']));

        self::assertSame(['User'], $paths['/users']['get']['tags']);
        self::assertArrayHasKey('200', $paths['/users']['get']['responses']);
        self::assertArrayHasKey(
            'Total-Count',
            $paths['/users']['get']['responses']['200']['headers'],
        );

        // The index 200 offers both pagination shapes as a oneOf, and the whole
        // document (validated below) proves that oneOf is valid OpenAPI 3.1.
        $indexSchema = $paths['/users']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertCount(2, $indexSchema['oneOf']);
        self::assertSame(
            '#/components/schemas/PaginationMeta',
            $indexSchema['oneOf'][0]['properties']['meta']['$ref'],
        );
        self::assertSame(
            '#/components/schemas/CursorPaginationMeta',
            $indexSchema['oneOf'][1]['properties']['meta']['$ref'],
        );
        self::assertArrayHasKey('201', $paths['/users']['post']['responses']);
        self::assertArrayHasKey('204', $paths['/users/{user}']['delete']['responses']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with path operations must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that a non-resource route (here an invokable controller) surfaces as
     * a documented operation carrying the shared x-undocumented success
     * envelope, and the emitted document stays valid OpenAPI 3.1.
     *
     * @return void
     */
    public function testUndocumentedRouteEmitsValidOperation(): void
    {
        $this->router()->get('status', PathInvokableController::class);

        $document = $this->export()->document;
        $schema   = $document['paths']['/status']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertTrue($schema['properties']['data']['x-undocumented']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with an undocumented operation must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that a non-resource route declaring a resource response body
     * references that resource's component schema, that the component survives
     * reachability filtering rather than dangling, and that the document stays
     * valid OpenAPI 3.1.
     *
     * @return void
     */
    public function testDeclaredResponseSchemaKeepsReferencedResource(): void
    {
        $this->router()->get('single', [PathResponseSchemaController::class, 'single']);

        $document = $this->export()->document;
        $schema   = $document['paths']['/single']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame('#/components/schemas/User', $schema['properties']['data']['$ref']);
        self::assertArrayHasKey('User', $document['components']['schemas']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document referencing a declared response resource must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that a write route emits a requestBody translated from its rules
     * source and the emitted document stays valid OpenAPI 3.1.
     *
     * @return void
     */
    public function testWriteRouteEmitsValidRequestBody(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);

        $document = $this->export()->document;
        $body     = $document['paths']['/articles']['post']['requestBody'];

        self::assertTrue($body['required']);
        self::assertArrayHasKey('title', $body['content']['application/json']['schema']['properties']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with a requestBody must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that a route excluded from an audience is absent from that
     * audience's paths while remaining present in an audience documenting it.
     *
     * @return void
     */
    public function testAudienceScopingFiltersRoutesBetweenAudiences(): void
    {
        $this->registerTwoAudiences();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $public = $this->export('public')->document['paths'];

        self::assertArrayHasKey('/organizations', $public);
        self::assertArrayNotHasKey('/tags', $public);

        $internal = $this->export('internal')->document['paths'];

        self::assertArrayHasKey('/organizations', $internal);
        self::assertArrayHasKey('/tags', $internal);
    }

    /**
     * Test that a resource reachable only through a route excluded from the
     * audience is dropped from that audience's component schemas while the
     * reachable resource is retained.
     *
     * @return void
     */
    public function testAudienceSchemasAreFilteredToReachableResources(): void
    {
        $this->registerTwoAudiences();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $public = $this->export('public')->document['components']['schemas'];

        self::assertArrayHasKey('Organization', $public);
        self::assertArrayNotHasKey('Tag', $public);
        self::assertArrayNotHasKey('User', $public);

        $internal = $this->export('internal')->document['components']['schemas'];

        self::assertArrayHasKey('Tag', $internal);
        self::assertArrayHasKey('Organization', $internal);
    }

    /**
     * Test that the shared pagination and error-envelope schemas survive the
     * per-audience filtering even for an audience documenting a single leaf
     * resource.
     *
     * @return void
     */
    public function testSharedSchemasSurviveAudienceFiltering(): void
    {
        $this->registerOrganizationRoute();

        $schemas = $this->export('public')->document['components']['schemas'];

        foreach (['PaginationMeta', 'PaginationLinks', 'CursorPaginationMeta', 'CursorPaginationLinks', 'ErrorEnvelope'] as $name) {
            self::assertArrayHasKey($name, $schemas);
        }

        self::assertArrayNotHasKey('User', $schemas);
        self::assertArrayNotHasKey('Post', $schemas);
        self::assertArrayNotHasKey('Tag', $schemas);
    }

    /**
     * Test that the --all flag writes one valid document per configured
     * audience, each scoped to its own routes.
     *
     * @return void
     */
    public function testCommandAllFlagWritesOneDocumentPerAudience(): void
    {
        $this->registerTwoAudiences();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $base     = sys_get_temp_dir() . '/api-toolkit-all-' . uniqid('', true) . '.json';
        $public   = $this->audiencePath($base, 'public');
        $internal = $this->audiencePath($base, 'internal');

        try {

            $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--output' => $base, '--all' => true]);

            self::assertSame(0, $exitCode);
            self::assertFileExists($public);
            self::assertFileExists($internal);

            $publicPaths   = $this->decodePaths($public);
            $internalPaths = $this->decodePaths($internal);

            self::assertArrayHasKey('/organizations', $publicPaths);
            self::assertArrayNotHasKey('/tags', $publicPaths);
            self::assertArrayHasKey('/tags', $internalPaths);

            self::assertTrue($this->validateJson($this->read($public))->isValid(), 'The public document must be valid.');
            self::assertTrue($this->validateJson($this->read($internal))->isValid(), 'The internal document must be valid.');

        } finally {
            @unlink($public);
            @unlink($internal);
        }
    }

    /**
     * Test that --audience exports the named audience to the resolved output
     * path, scoped to that audience's routes.
     *
     * @return void
     */
    public function testCommandExportsNamedAudienceToResolvedPath(): void
    {
        $this->registerTwoAudiences();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $path = sys_get_temp_dir() . '/api-toolkit-named-' . uniqid('', true) . '.json';

        try {

            $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--output' => $path, '--audience' => 'internal']);

            self::assertSame(0, $exitCode);
            self::assertFileExists($path);

            self::assertArrayHasKey('/tags', $this->decodePaths($path));

        } finally {
            @unlink($path);
        }
    }

    /**
     * Test that an unknown audience name fails with a clear error and a
     * non-zero exit code.
     *
     * @return void
     */
    public function testCommandFailsOnUnknownAudience(): void
    {
        $this->registerUserRoutes();

        $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--audience' => 'nonexistent']);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Unknown audience', Artisan::output());
    }

    /**
     * Test that an authenticated route emits per-operation security and the
     * matching securitySchemes component, and the document stays valid OpenAPI
     * 3.1.
     *
     * @return void
     */
    public function testAuthenticatedRouteEmitsValidSecurity(): void
    {
        $this->configureGuards();
        $this->router()->get('users', [PathFixtureController::class, 'index'])->middleware('auth:api,admin');

        $document = $this->export()->document;

        self::assertSame(
            [['bearerAuth' => []], ['basicAuth' => []]],
            $document['paths']['/users']['get']['security'],
        );

        $schemes = $document['components']['securitySchemes'];

        self::assertSame(['type' => 'http', 'scheme' => 'bearer'], $schemes['bearerAuth']);
        self::assertSame(['type' => 'http', 'scheme' => 'basic'], $schemes['basicAuth']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with security requirements must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that the resource REST responses survive full assembly: show and
     * store wrap the User reference in the single envelope under 200 and 201,
     * and destroy emits an empty 204 with no body.
     *
     * @return void
     */
    public function testResourceResponseBodiesSurviveAssembly(): void
    {
        $this->registerUserRoutes();

        $paths    = $this->export()->document['paths'];
        $envelope = (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User');

        self::assertSame(
            $envelope,
            $paths['/users/{user}']['get']['responses']['200']['content']['application/json']['schema'],
        );
        self::assertSame(
            $envelope,
            $paths['/users']['post']['responses']['201']['content']['application/json']['schema'],
        );

        $destroy = $paths['/users/{user}']['delete']['responses']['204'];

        self::assertSame(['description' => 'The resource was deleted.'], $destroy);
        self::assertArrayNotHasKey('content', $destroy);
    }

    /**
     * Test that an action's documented @throws surfaces as a per-operation
     * error response end to end: show carries a 404 referencing the shared
     * error envelope with the NotFoundException example, and store a 409 with
     * the ConflictException example.
     *
     * @return void
     */
    public function testThrowsDerivedErrorResponsesSurviveAssembly(): void
    {
        $this->registerUserRoutes();

        $paths = $this->export()->document['paths'];

        $notFound = $paths['/users/{user}']['get']['responses']['404']['content']['application/json'];

        self::assertSame('#/components/schemas/ErrorEnvelope', $notFound['schema']['$ref']);
        self::assertSame(
            ['NotFoundException' => ['value' => ['error' => ['status' => 404, 'code' => 10103]]]],
            $notFound['examples'],
        );

        $conflict = $paths['/users']['post']['responses']['409']['content']['application/json'];

        self::assertSame('#/components/schemas/ErrorEnvelope', $conflict['schema']['$ref']);
        self::assertSame(
            ['ConflictException' => ['value' => ['error' => ['status' => 409, 'code' => 10108]]]],
            $conflict['examples'],
        );
    }

    /**
     * Test that the honest baseline error statuses attach per REST action end
     * to end: every action carries 401/403/500, an item read or write also 404,
     * and a write also 422, merged with the throws-derived codes.
     *
     * @return void
     */
    public function testBaselineErrorStatusesAttachPerAction(): void
    {
        $this->registerUserRoutes();

        $paths = $this->export()->document['paths'];

        self::assertSame([200, 401, 403, 500], array_keys($paths['/users']['get']['responses']));
        self::assertSame([201, 401, 403, 409, 422, 500], array_keys($paths['/users']['post']['responses']));
        self::assertSame([200, 401, 403, 404, 500], array_keys($paths['/users/{user}']['get']['responses']));
        self::assertSame([200, 401, 403, 404, 422, 500], array_keys($paths['/users/{user}']['put']['responses']));
        self::assertSame([204, 401, 403, 404, 500], array_keys($paths['/users/{user}']['delete']['responses']));
    }

    /**
     * Test that a to-many relation emits an array of references to the related
     * component and that the referenced resource survives reachability
     * filtering rather than dangling.
     *
     * @return void
     */
    public function testToManyRelationEmitsArrayOfReferencesAndKeepsChild(): void
    {
        $this->registerUserRoutes();

        $document = $this->export()->document;
        $posts    = $document['components']['schemas']['User']['properties']['posts'];

        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']],
            $posts,
        );

        self::assertArrayHasKey('Post', $document['components']['schemas']);
    }

    /**
     * Test that a nullable resource field emits the JSON Schema 2020-12
     * nullable type union rather than the OpenAPI 3.0 nullable keyword, end to
     * end.
     *
     * @return void
     */
    public function testNullableFieldEmitsTypeUnion(): void
    {
        $this->registerUserRoutes();

        $document  = $this->export()->document;
        $createdAt = $document['components']['schemas']['User']['properties']['created_at'];

        self::assertSame(['string', 'null'], $createdAt['type']);
        self::assertSame('date-time', $createdAt['format']);
    }

    /**
     * Test that the export runs without live column introspection: cast-backed
     * and declared fields stay typed, a purely column-inferred scalar degrades
     * to undocumented, and the document still validates as OpenAPI 3.1.
     *
     * @return void
     */
    public function testExportSurvivesWithoutColumnIntrospection(): void
    {
        $this->registerUserRoutes();

        $inner = $this->makeApplication()->make(SchemaIntrospectionProvider::class);

        $this->makeApplication()->instance(
            SchemaIntrospectionProvider::class,
            new ColumnlessIntrospectionProvider($inner),
        );

        SchemaCompiler::clearCache();

        $document = $this->export()->document;
        $schemas  = $document['components']['schemas'];

        // A boolean cast keeps the field typed even without a backing column.
        self::assertSame(['type' => 'boolean'], $schemas['Post']['properties']['published']);

        // A declared timestamp field keeps its nullable date-time contract.
        self::assertSame(
            ['type' => ['string', 'null'], 'format' => 'date-time'],
            $schemas['User']['properties']['created_at'],
        );

        // A scalar resolvable only from its column degrades to undocumented
        // once neither a column nor a mappable cast resolves it.
        self::assertSame(['x-undocumented' => true], $schemas['User']['properties']['status']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document exported without column introspection must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
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
        $config->set('auth.guards.admin', ['driver' => 'basic']);
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
     * Validate a document array against the bundled (opis-adapted) OpenAPI 3.1
     * meta-schema.
     *
     * @param  array<string, mixed>  $document
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateAgainstMetaSchema(array $document): ValidationResult
    {
        return $this->validator()->validate(Helper::toJSON($document), self::META_SCHEMA_ID);
    }

    /**
     * Validate a raw JSON document string against the bundled meta-schema.
     *
     * Decoding to objects (rather than associative arrays) preserves the
     * distinction between an empty JSON object and an empty array, so the
     * document's empty `paths` object stays an object and remains schema-valid.
     *
     * @param  string  $json
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateJson(string $json): ValidationResult
    {
        return $this->validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), self::META_SCHEMA_ID);
    }

    /**
     * Build a validator with the dialect and meta-schema registered.
     *
     * @return \Opis\JsonSchema\Validator
     */
    private function validator(): Validator
    {
        $validator = new Validator;
        $resolver  = $validator->resolver();

        assert($resolver instanceof SchemaResolver);

        $resolver->registerRaw($this->dialectSchema(), self::DIALECT_ID);
        $resolver->registerRaw($this->metaSchema(), self::META_SCHEMA_ID);

        return $validator;
    }

    /**
     * Load the OpenAPI 3.1 meta-schema, applying the documented opis
     * compatibility transform.
     *
     * @return string
     */
    private function metaSchema(): string
    {
        /** @var array<string, mixed> $schema */
        $schema = json_decode($this->fixture('openapi-3.1-schema.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->adaptForOpis($schema);

        return json_encode($schema, JSON_THROW_ON_ERROR);
    }

    /**
     * Load the JSON Schema 2020-12 dialect document.
     *
     * @return string
     */
    private function dialectSchema(): string
    {
        return $this->fixture('json-schema-2020-12.json');
    }

    /**
     * Apply the two opis/json-schema compatibility transforms in place: rewrite
     * the Schema Object's `$dynamicRef: "#meta"` to the equivalent static
     * `$ref: "#/$defs/schema"`, and relax every `unevaluatedProperties: false`
     * to `true`. Both work around opis annotation/reference gaps without
     * weakening any validity-bearing constraint; see the fixtures README.
     *
     * @param  array<string, mixed>  $node
     * @return void
     */
    private function adaptForOpis(array &$node): void
    {
        if (($node['$dynamicRef'] ?? null) === '#meta') {
            unset($node['$dynamicRef']);
            $node['$ref'] = '#/$defs/schema';
        }

        if (($node['unevaluatedProperties'] ?? null) === false) {
            $node['unevaluatedProperties'] = true;
        }

        foreach ($node as &$value) {
            if (!is_array($value)) {
                continue;
            }

            $this->adaptForOpis($value);
        }
    }

    /**
     * Read a bundled fixture file by name.
     *
     * @param  string  $name
     * @return string
     */
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/openapi/' . $name);

        assert(is_string($contents));

        return $contents;
    }

    /**
     * Format a validation result's errors for a failure message.
     *
     * @param  \Opis\JsonSchema\ValidationResult  $result
     * @return string
     */
    private function formatErrors(ValidationResult $result): string
    {
        $error = $result->error();

        if ($error === null) {
            return '(no error)';
        }

        return json_encode((new ErrorFormatter)->format($error), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Register a full REST route set for the user resource so the resource
     * schemas are reachable from the exported paths.
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
     * Register a public organization route, a reachable leaf resource.
     *
     * @return void
     */
    private function registerOrganizationRoute(): void
    {
        $this->router()->get('organizations', [PathOrganizationController::class, 'index']);
    }

    /**
     * Register the internal-only tag route.
     *
     * @return void
     */
    private function registerTagInternalRoute(): void
    {
        $this->router()->get('tags', [PathTagInternalController::class, 'index']);
    }

    /**
     * Declare a public and an internal audience on the config repository.
     *
     * @return void
     */
    private function registerTwoAudiences(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);
    }

    /**
     * Derive a per-audience output path the way the command does.
     *
     * @param  string  $base
     * @param  string  $audience
     * @return string
     */
    private function audiencePath(string $base, string $audience): string
    {
        return substr($base, 0, -strlen('.json')) . '.' . $audience . '.json';
    }

    /**
     * Decode the paths object from a written document by path.
     *
     * @param  string  $path
     * @return array<string, mixed>
     */
    private function decodePaths(string $path): array
    {
        /** @var array{paths?: array<string, mixed>} $document */
        $document = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);

        return $document['paths'] ?? [];
    }

    /**
     * Read a written document by path, asserting the read succeeds.
     *
     * @param  string  $path
     * @return string
     */
    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents);

        return $contents;
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
